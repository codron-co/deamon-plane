<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Coolify\Dto\CoolifyDeployment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeploymentPollWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Cache::flush();
    }

    public function test_manual_cancel_updates_deployment_without_marking_site_error(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Active, 'name' => 'Moon Agro']);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-cancel-1',
            'started_at' => now()->subMinutes(2),
        ]);

        $terminal = app(CoolifyDeploymentSync::class)->applyExisting(
            $deployment,
            CoolifyDeployment::fromArray([
                'uuid' => 'dep-cancel-1',
                'status' => 'cancelled',
                'message' => 'Cancelled by user',
            ]),
        );

        $this->assertTrue($terminal);
        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Cancelled, $deployment->status);
        $this->assertNotNull($deployment->finished_at);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    public function test_poll_job_for_manual_cancel_keeps_site_active(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'token',
        ]);
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'app-1',
            'coolify_connection_id' => $connection->id,
        ]);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-m1',
            'started_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-m1' => Http::response([
                'uuid' => 'dep-m1',
                'status' => 'cancelled',
                'message' => 'Stopped',
            ], 200),
        ]);

        Queue::fake();

        (new PollDeploymentJob($deployment->id))->handle(
            app(\App\Services\Sites\SiteProvisioner::class),
            app(\App\Services\Coolify\CoolifyApplicationService::class),
            app(\App\Services\Sites\ChannelSwitcher::class),
            app(CoolifyDeploymentSync::class),
        );

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Cancelled, $deployment->status);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_jobs_index_maps_cancelled_deployment_widget_status(): void
    {
        $site = Site::factory()->create(['name' => 'Meyyit']);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Cancelled,
            'trigger' => DeploymentTrigger::Manual,
            'updated_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonPath('deployments.0.title', 'Meyyit')
            ->assertJsonPath('deployments.0.status', 'cancelled')
            ->assertJsonPath('deployments.0.progress', 100);
    }

    public function test_jobs_index_kicks_poll_for_stale_active_deployments(): void
    {
        $site = Site::factory()->create();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-stale',
            'started_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Queue::fake();

        $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk();

        Queue::assertPushed(PollDeploymentJob::class, function (PollDeploymentJob $job) use ($deployment): bool {
            return $job->deploymentId === $deployment->id;
        });
    }

    public function test_jobs_index_does_not_kick_fresh_active_deployments(): void
    {
        $site = Site::factory()->create();
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-fresh',
            'started_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
        ]);

        Queue::fake();

        $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk();

        Queue::assertNotPushed(PollDeploymentJob::class);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
