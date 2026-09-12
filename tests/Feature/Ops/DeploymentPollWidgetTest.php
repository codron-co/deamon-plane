<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
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
            ->assertJsonPath('deployments.0.title', __('ops.jobs.deployment').' · Meyyit')
            ->assertJsonPath('deployments.0.status', 'cancelled')
            ->assertJsonPath('deployments.0.progress', 100)
            ->assertJsonPath('deployments.0.actions.dismiss', true)
            ->assertJsonPath('deployments.0.actions.cancel', false);
    }

    public function test_deployment_widget_row_names_kind_site_and_status(): void
    {
        $site = Site::factory()->create(['name' => 'beyazlar']);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-label-1',
        ]);

        $row = ($deployment->fresh(['site']) ?? $deployment)->toWidget();

        $this->assertSame(__('ops.jobs.deployment').' · beyazlar', $row['title']);
        $this->assertSame(__('ops.jobs.deployment'), $row['kind_label']);
        $this->assertSame('beyazlar', $row['subject']);
        $this->assertSame(__('ops.deploy_trigger.manual'), $row['detail']);
        $this->assertSame(__('ops.deploy_status.queued'), $row['status_label']);
        $this->assertSame(
            __('ops.deploy_trigger.manual').' · '.__('ops.deploy_status.queued'),
            $row['message'],
        );
    }

    public function test_jobs_index_labels_background_job_with_kind_and_subject(): void
    {
        $operator = $this->operator();
        OpsBackgroundJob::query()->create([
            'type' => 'sites.live_sync',
            'title' => 'Live Sync',
            'status' => 'running',
            'progress' => 40,
            'message' => '2/5',
            'payload' => ['site_ids' => ['site-1'], 'subject' => 'beyazlar'],
            'actor_user_id' => $operator->id,
        ]);

        $this->actingAs($operator)
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonPath('jobs.0.title', __('ops.jobs.live_sync').' · beyazlar')
            ->assertJsonPath('jobs.0.kind_label', __('ops.jobs.live_sync'))
            ->assertJsonPath('jobs.0.detail', '2/5')
            ->assertJsonPath('jobs.0.status_label', __('ops.jobs.status.running'));
    }

    public function test_background_job_without_subject_falls_back_to_site_count(): void
    {
        $job = OpsBackgroundJob::query()->create([
            'type' => 'sites.bulk_app_health_fix',
            'title' => 'App',
            'status' => 'queued',
            'progress' => 0,
            'payload' => ['site_ids' => ['a', 'b', 'c']],
        ]);

        $this->assertSame(
            __('ops.jobs.bulk_app_health_fix').' · '.trans_choice('ops.jobs.subject_sites', 3, ['count' => 3]),
            $job->toWidget()['title'],
        );
    }

    public function test_jobs_index_includes_coolify_queue_for_deamon_sites_only(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'token',
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $site = Site::factory()->create([
            'name' => 'Moon Agro',
            'coolify_app_uuid' => 'app-moon',
            'coolify_connection_id' => $connection->id,
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments' => Http::response([
                '1' => [
                    'deployment_uuid' => 'dep-moon-q',
                    'status' => 'queued',
                    'application_name' => 'Moon Agro',
                    'application_id' => 10,
                ],
                '2' => [
                    'deployment_uuid' => 'dep-plane-q',
                    'status' => 'queued',
                    'application_name' => 'Deamon Plane',
                    'application_id' => 11,
                ],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonFragment(['coolify_deployment_uuid' => 'dep-moon-q'])
            ->assertJsonMissing(['coolify_deployment_uuid' => 'dep-plane-q']);

        $this->assertSame($site->id, Site::query()->where('name', 'Moon Agro')->value('id'));
    }

    public function test_cancel_deployment_calls_coolify_and_updates_local_row(): void
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
            'coolify_deployment_uuid' => 'dep-run-1',
            'started_at' => now()->subMinutes(2),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-run-1/cancel' => Http::response([
                'message' => 'Deployment cancelled successfully.',
                'deployment_uuid' => 'dep-run-1',
                'status' => 'cancelled-by-user',
            ], 200),
            'https://coolify.example/api/v1/deployments/dep-run-1' => Http::response([
                'deployment_uuid' => 'dep-run-1',
                'status' => 'cancelled-by-user',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->postJson(route('ops.jobs.deployments.cancel', $deployment))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deployment.status', 'cancelled');

        $this->assertSame(DeploymentStatus::Cancelled, $deployment->fresh()->status);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    public function test_force_start_queued_deployment_cancels_then_instant_starts(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'token',
        ]);
        $site = Site::factory()->create([
            'coolify_app_uuid' => 'app-1',
            'coolify_connection_id' => $connection->id,
        ]);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-q-1',
            'started_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-q-1/cancel' => Http::response([
                'message' => 'Deployment cancelled successfully.',
                'deployment_uuid' => 'dep-q-1',
                'status' => 'cancelled-by-user',
            ], 200),
            'https://coolify.example/api/v1/applications/app-1/start*' => Http::response([
                'message' => 'Deployment request queued.',
                'deployment_uuid' => 'dep-new-1',
            ], 200),
            'https://coolify.example/api/v1/deployments/dep-new-1' => Http::response([
                'deployment_uuid' => 'dep-new-1',
                'status' => 'in_progress',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->postJson(route('ops.jobs.deployments.force-start', $deployment))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deployment.coolify_deployment_uuid', 'dep-new-1')
            ->assertJsonPath('deployment.status', 'running');

        $this->assertSame(DeploymentStatus::Cancelled, $deployment->fresh()->status);
        $this->assertDatabaseHas('deployments', [
            'coolify_deployment_uuid' => 'dep-new-1',
            'site_id' => $site->id,
        ]);
    }

    public function test_destroy_completed_background_job(): void
    {
        $operator = $this->operator();
        $job = OpsBackgroundJob::query()->create([
            'type' => 'sites.live_sync',
            'title' => 'Live Sync',
            'status' => 'completed',
            'progress' => 100,
            'message' => 'Done',
            'actor_user_id' => $operator->id,
        ]);

        $this->actingAs($operator)
            ->deleteJson(route('ops.jobs.destroy', $job))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('ops_background_jobs', ['id' => $job->id]);
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
