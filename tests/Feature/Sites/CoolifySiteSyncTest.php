<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifySiteSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-ops-token';

    private const APP = 'sync-app-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_site_sync_upserts_coolify_deployments_without_touching_status_or_secrets(): void
    {
        $site = $this->site();
        $appKey = $site->app_key_encrypted;
        $agentSecret = $site->agent_secret_encrypted;

        Deployment::factory()->create([
            'site_id' => $site->id,
            'coolify_deployment_uuid' => 'dep-existing',
            'status' => DeploymentStatus::Finished,
            'commit_sha' => 'oldsha',
            'finished_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload(), 200),
            'https://coolify.example/api/v1/deployments/applications/'.self::APP.'*' => Http::response([
                [
                    'uuid' => 'dep-existing',
                    'status' => 'finished',
                    'commit' => 'abc1234deadbeef',
                    'created_at' => '2026-09-01T10:00:00Z',
                    'updated_at' => '2026-09-01T10:04:00Z',
                ],
                [
                    'deployment_uuid' => 'dep-new',
                    'status' => 'failed',
                    'commit' => 'fff9999',
                    'message' => 'Build failed',
                    'created_at' => '2026-09-02T11:00:00Z',
                    'updated_at' => '2026-09-02T11:02:00Z',
                    'logs' => 'drop-me-from-raw',
                ],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.sync', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame($appKey, $site->app_key_encrypted);
        $this->assertSame($agentSecret, $site->agent_secret_encrypted);
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $site->coolify_project_uuid);
        $this->assertSame(Channel::Beta, $site->channel);

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'coolify_deployment_uuid' => 'dep-existing',
            'commit_sha' => 'abc1234deadbeef',
            'status' => DeploymentStatus::Finished->value,
        ]);
        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'coolify_deployment_uuid' => 'dep-new',
            'status' => DeploymentStatus::Failed->value,
            'trigger' => DeploymentTrigger::Manual->value,
            'commit_sha' => 'fff9999',
            'error_message' => 'Build failed',
        ]);
        $this->assertSame(2, $site->deployments()->count());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/applications/'.self::APP));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/deployments/applications/'.self::APP));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_site_sync_does_not_regress_terminal_row_to_in_progress(): void
    {
        $site = $this->site();
        Deployment::factory()->create([
            'site_id' => $site->id,
            'coolify_deployment_uuid' => 'dep-done',
            'status' => DeploymentStatus::Finished,
            'finished_at' => now()->subMinutes(10),
            'commit_sha' => 'keep-me',
        ]);

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload(), 200),
            'https://coolify.example/api/v1/deployments/applications/'.self::APP.'*' => Http::response([
                [
                    'uuid' => 'dep-done',
                    'status' => 'in_progress',
                    'commit' => 'should-not-win',
                ],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.sync', $site))
            ->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'coolify_deployment_uuid' => 'dep-done',
            'status' => DeploymentStatus::Finished->value,
            'commit_sha' => 'keep-me',
        ]);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    public function test_get_site_sync_does_not_call_coolify(): void
    {
        $site = $this->site();
        Http::fake();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.sync.get', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('sites.flash.sync_get'));

        Http::assertNothingSent();
    }

    public function test_show_has_post_sync_and_no_get_sync_link(): void
    {
        $site = $this->site();
        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload(), 200),
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.menu.coolify'), false)
            ->assertSee('data-confirm="'.__('sites.detail.sync_confirm', ['name' => $site->name]).'"', false)
            ->getContent();

        $this->assertStringContainsString('action="'.route('ops.sites.sync', $site).'"', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/sync"/', $html);
    }

    public function test_bulk_sync_pulls_coolify_for_sites_with_app_uuid(): void
    {
        $site = $this->site();
        $skipped = Site::factory()->create([
            'name' => 'No Coolify Uuid',
            'coolify_app_uuid' => null,
        ]);
        $status = $site->status;
        $appKey = $site->app_key_encrypted;

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload(), 200),
            'https://coolify.example/api/v1/deployments/applications/'.self::APP.'*' => Http::response([], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.sync'), ['all' => '1'])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');

        $this->assertSame($status, $site->fresh()->status);
        $this->assertSame($appKey, $site->fresh()->app_key_encrypted);
        $this->assertNull($skipped->fresh()->coolify_project_uuid);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/applications/'.self::APP));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), (string) $skipped->primary_domain));
    }

    public function test_get_bulk_sync_does_not_call_coolify(): void
    {
        $this->site();
        Http::fake();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.bulk.sync.get'))
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.sync_get'));

        Http::assertNothingSent();
    }

    public function test_viewer_cannot_sync_site(): void
    {
        $site = $this->site();
        Http::fake();

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.sync', $site))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationPayload(): array
    {
        return [
            'uuid' => self::APP,
            'git_branch' => 'beta',
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'github_app_uuid' => 'r08cws800oow8w008880c8og',
            'destination' => [
                'server' => ['uuid' => 'no48ksggg0k8sk4o4w08gks8'],
            ],
            'environment' => [
                'uuid' => 'sns276euzsz2fprqg3xgfz17',
                'project' => ['uuid' => 'z8ocg8k04ww8osssccc088c0'],
            ],
        ];
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
