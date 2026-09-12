<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Ops\OpsJobRunner;
use App\Services\Sites\SiteAppHealthFixer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A Plane job that only hands deploys to Coolify must not report a finished
 * build, and Force start must not read as a cancellation.
 */
class TruthfulJobCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'coolify-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Queue::fake();
        Sleep::fake();
        Http::preventStrayRequests();
        Cache::flush();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        $this->app->setLocale('tr');
    }

    public function test_bulk_redeploy_says_deploys_were_triggered_not_done(): void
    {
        $site = $this->site();
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $job = $this->job('sites.bulk_deploy', [$site]);
        $message = app(OpsJobRunner::class)->run($job);
        $job->markCompleted($message);

        $this->assertSame(
            __('site_ops.redeploy.bulk').' '.__('ops.bulk.triggered', ['ok' => 1])
            .' — '.__('ops.bulk.triggered_note'),
            $message,
        );
        $this->assertStringNotContainsString('tamam', $message);

        $widget = $job->fresh()?->toWidget() ?? [];
        $this->assertSame(__('ops.jobs.status.triggered'), $widget['status_label']);
        $this->assertNotSame(__('ops.jobs.status.completed'), $widget['status_label']);
    }

    public function test_bulk_pin_and_follow_head_and_channel_are_trigger_only(): void
    {
        foreach (['sites.bulk_pin', 'sites.bulk_follow_head', 'sites.bulk_channel'] as $type) {
            $job = $this->job($type, [], ['ref' => 'abc1234', 'channel' => 'beta']);
            $job->markCompleted('x');

            $this->assertTrue($job->triggersRemoteWork(), $type.' only triggers Coolify work.');
            $this->assertSame(__('ops.jobs.status.triggered'), $job->statusLabel(), $type);
        }
    }

    public function test_work_plane_finishes_itself_still_reads_as_done(): void
    {
        foreach (['sites.live_sync', 'themes.catalog_sync', 'sites.bulk_publish_status', 'sites.bulk_auto_deploy'] as $type) {
            $job = $this->job($type, []);
            $job->markCompleted('x');

            $this->assertFalse($job->triggersRemoteWork(), $type.' finishes inside Plane.');
            $this->assertSame(__('ops.jobs.status.completed'), $job->statusLabel(), $type);
        }
    }

    public function test_app_health_sweep_claims_a_trigger_only_when_a_redeploy_ran(): void
    {
        $job = $this->job('sites.bulk_app_health_fix', [], ['fix' => 'bind_domains']);
        $job->markCompleted('x', ['sites' => [], 'deploy_triggered' => false]);
        $this->assertSame(__('ops.jobs.status.completed'), $job->statusLabel());

        $deploying = $this->job('sites.bulk_app_health_fix', [], ['fix' => 'redeploy']);
        $deploying->markCompleted('x', ['sites' => [], 'deploy_triggered' => true]);
        $this->assertSame(__('ops.jobs.status.triggered'), $deploying->statusLabel());
    }

    public function test_app_health_summary_only_warns_about_coolify_when_it_deployed(): void
    {
        $fixer = app(SiteAppHealthFixer::class);
        $result = ['ok' => 2, 'failed' => 0, 'skipped' => 0];

        $this->assertStringNotContainsString(
            __('ops.bulk.triggered_note'),
            $fixer->summarize($result),
        );
        $this->assertStringContainsString(
            __('ops.bulk.triggered_note'),
            $fixer->summarize($result, deployTriggered: true),
        );
    }

    public function test_no_js_bulk_redeploy_flash_says_triggered(): void
    {
        $site = $this->site();
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.deploy'), ['site_ids' => [$site->id]])
            ->assertRedirect(route('ops.sites'));

        $flash = (string) session('status');
        $this->assertStringContainsString(__('ops.bulk.triggered', ['ok' => 1]), $flash);
        $this->assertStringContainsString(__('ops.bulk.triggered_note'), $flash);
    }

    public function test_force_start_leaves_one_running_row_and_no_cancelled_ghost(): void
    {
        $site = $this->site();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-queued',
            'started_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-queued/cancel' => Http::response([
                'deployment_uuid' => 'dep-queued',
                'status' => 'cancelled-by-user',
            ], 200),
            'https://coolify.example/api/v1/applications/app-force/start*' => Http::response([
                'deployment_uuid' => 'dep-forced',
            ], 200),
            'https://coolify.example/api/v1/deployments/dep-forced' => Http::response([
                'deployment_uuid' => 'dep-forced',
                'status' => 'in_progress',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->postJson(route('ops.jobs.deployments.force-start', $deployment))
            ->assertOk()
            ->assertJsonPath('message', __('ops.jobs.force_started'))
            ->assertJsonPath('deployment.status', 'running')
            ->assertJsonPath('deployment.status_label', __('ops.deploy_status.in_progress'));

        $this->assertSame(1, $site->deployments()->count());
        $this->assertSame(
            0,
            $site->deployments()->where('status', DeploymentStatus::Cancelled->value)->count(),
            'Force start must not leave a cancelled row behind.',
        );
    }

    public function test_force_start_that_coolify_refuses_says_so_in_turkish(): void
    {
        $site = $this->site();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-queued',
            'started_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-queued/cancel' => Http::response([
                'deployment_uuid' => 'dep-queued',
                'status' => 'cancelled-by-user',
            ], 200),
            'https://coolify.example/api/v1/applications/app-force/start*' => Http::response([
                'message' => '',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->postJson(route('ops.jobs.deployments.force-start', $deployment))
            ->assertStatus(422)
            ->assertJsonPath('message', __('ops.jobs.force_start_failed'));

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Cancelled, $deployment->status);
        $this->assertSame(__('ops.jobs.force_start_aborted'), $deployment->error_message);
        $this->assertStringNotContainsString('Coolify deployment', (string) $deployment->error_message);
    }

    public function test_cancelled_deploy_without_a_coolify_message_is_described_in_turkish(): void
    {
        $site = $this->site();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-cancel',
            'started_at' => now()->subMinute(),
        ]);

        app(CoolifyDeploymentSync::class)->applyExisting($deployment, CoolifyDeployment::fromArray([
            'uuid' => 'dep-cancel',
            'status' => 'cancelled',
        ]));

        $deployment->refresh();
        $this->assertSame(__('ops.deploy_failure.cancelled'), $deployment->error_message);
        $this->assertStringNotContainsString('Coolify deployment cancelled.', (string) $deployment->error_message);
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'app-force',
            'coolify_connection_id' => $connection->id,
        ]);
    }

    /**
     * @param  list<Site>  $sites
     * @param  array<string, mixed>  $payload
     */
    private function job(string $type, array $sites, array $payload = []): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create([
            'type' => $type,
            'title' => $type,
            'status' => 'queued',
            'progress' => 0,
            'payload' => array_merge($payload, [
                'site_ids' => array_map(static fn (Site $site) => $site->id, $sites),
            ]),
            'actor_user_id' => $this->operator()->id,
        ]);
    }

    private function coolifyResponse(Request $request)
    {
        $url = $request->url();

        if (str_contains($url, '/envs')) {
            return Http::response([], 200);
        }

        if ($request->method() === 'POST' && str_contains($url, '/deploy')) {
            return Http::response([
                'deployments' => [['deployment_uuid' => 'dep-'.bin2hex(random_bytes(4))]],
            ], 200);
        }

        if (str_contains($url, '/deployments/applications/')) {
            return Http::response([], 200);
        }

        return Http::response([
            'uuid' => 'app-force',
            'build_pack' => 'dockercompose',
            'git_commit_sha' => 'abc1234',
            'is_auto_deploy_enabled' => false,
            'settings' => ['is_auto_deploy_enabled' => false],
        ], 200);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
