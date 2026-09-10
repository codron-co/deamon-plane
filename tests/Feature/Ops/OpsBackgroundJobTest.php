<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsBackgroundJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_ops_shell_includes_jobs_widget(): void
    {
        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-ops-jobs', false)
            ->assertSee('js/ops-jobs.js', false)
            ->assertSee('js/ops-async.js', false);
    }

    public function test_ajax_live_sync_queues_a_job_instead_of_redirecting(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'live.example.test',
        ]);

        Http::fake([
            'https://live.example.test/*' => Http::response('<html><link rel="icon" href="/fav.ico"></html>', 200),
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['job' => ['id', 'type', 'title', 'status']]);

        $job = OpsBackgroundJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame('sites.live_sync', $job->type);
        $this->assertContains($job->status, ['queued', 'running', 'completed']);

        $this->actingAs($operator)
            ->getJson(route('ops.jobs.show', $job))
            ->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.status', 'completed');

        $this->assertSame(200, $site->fresh()->last_live_http_status);
    }

    public function test_html_live_sync_still_redirects(): void
    {
        Site::factory()->create(['primary_domain' => 'html.example.test']);
        Http::fake([
            'https://html.example.test/*' => Http::response('ok', 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');
    }

    public function test_ajax_mutating_post_returns_json_instead_of_following_a_reload(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Draft]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->postJson(route('ops.sites.provision', $site))
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_viewer_cannot_queue_or_list_jobs(): void
    {
        Site::factory()->create(['primary_domain' => 'view.example.test']);
        Http::fake();

        $this->actingAs($this->viewer())
            ->postJson(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->getJson(route('ops.jobs'))
            ->assertForbidden();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return $user;
    }
}
