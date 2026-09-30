<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\WaitingDeployAction;
use App\Jobs\StartWaitingDeploysJob;
use App\Jobs\SwitchSiteChannelJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsJobRunner;
use App\Services\Ops\WaitingDeployDispatcher;
use App\Services\Ops\WaitingDeployQueue;
use App\Services\Sites\ChannelSwitcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A single-site deploy that finds the per-server build cap full waits in the
 * Plane line (`waiting`) instead of failing with `deploy_busy`, and the
 * dispatcher starts it FIFO once a slot frees.
 */
class WaitingDeployQueueTest extends TestCase
{
    use RefreshDatabase;

    private CoolifyConnection $connection;

    private int $deploySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Cache::flush();
        // Poll jobs and dispatcher kicks are asserted, not run inline.
        Queue::fake();
        Sleep::fake();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        config()->set('ops.coolify.retry.max_attempts', 1);

        $this->connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'waiting-queue-token',
        ]);
    }

    public function test_default_cap_two_allows_two_builds_and_queues_the_third(): void
    {
        $this->assertSame(2, (int) config('ops.coolify.deploy.max_concurrent_per_server'));

        [$a, $b, $c] = [$this->site('app-a'), $this->site('app-b'), $this->site('app-c')];
        $this->fakeCoolify();
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('ops.sites.deploy', $a))
            ->assertSessionHas('status', __('site_ops.redeploy.done', ['name' => $a->name]));
        $this->actingAs($operator)->post(route('ops.sites.deploy', $b))
            ->assertSessionHas('status', __('site_ops.redeploy.done', ['name' => $b->name]));
        $this->actingAs($operator)->post(route('ops.sites.deploy', $c))
            ->assertSessionMissing('error')
            ->assertSessionHas('status', __('site_ops.queue.queued', ['running' => 2, 'position' => 1]));

        $this->assertSame(DeploymentStatus::InProgress, $a->deployments()->sole()->status);
        $this->assertSame(DeploymentStatus::InProgress, $b->deployments()->sole()->status);
        $waiting = $c->deployments()->sole();
        $this->assertSame(DeploymentStatus::Waiting, $waiting->status);
        $this->assertSame(WaitingDeployAction::Redeploy, $waiting->queue_action);
        $this->assertSame($operator->id, $waiting->requested_by);
        $this->assertNull($waiting->coolify_deployment_uuid);
        $this->assertSame(2, $this->deployPosts());
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.deploy_queued', 'subject_id' => $c->id]);
    }

    public function test_queued_deploy_starts_on_the_same_row_when_a_slot_frees(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $busy = $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-next');
        $this->fakeCoolify();

        $outcome = app(WaitingDeployQueue::class)->request($site, WaitingDeployAction::Redeploy, ['force' => true], $this->operator());
        $this->assertTrue($outcome->queued);
        $this->assertSame(0, $this->deployPosts());

        // The build ending kicks the line at once.
        $busy->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        Queue::assertPushed(StartWaitingDeploysJob::class);

        $result = app(WaitingDeployDispatcher::class)->tick();

        $this->assertSame(1, $result['started']);
        $row = $outcome->deployment->fresh();
        $this->assertSame(DeploymentStatus::InProgress, $row->status);
        $this->assertNotNull($row->coolify_deployment_uuid);
        $this->assertNotNull($row->started_at);
        $this->assertSame(1, $row->queue_attempts);
        $this->assertSame(1, $site->deployments()->count(), 'The waiting row becomes the build; no second row.');
        $this->assertSame(1, $this->deployPosts());
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.deploy_queue_started', 'subject_id' => $site->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.redeployed', 'subject_id' => $site->id]);
    }

    public function test_waiting_rows_start_first_in_first_out_and_respect_the_cap(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $busy = $this->openBuild($this->site('app-busy'));
        $first = $this->site('app-first');
        $second = $this->site('app-second');
        $this->fakeCoolify();
        $queue = app(WaitingDeployQueue::class);

        $one = $queue->request($first, WaitingDeployAction::Redeploy);
        $this->travel(5)->seconds();
        $two = $queue->request($second, WaitingDeployAction::Redeploy);
        $this->assertSame(1, $one->position);
        $this->assertSame(2, $two->position);

        // Still full: nothing starts.
        $this->assertSame(0, app(WaitingDeployDispatcher::class)->tick()['started']);

        $busy->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        $this->assertSame(1, app(WaitingDeployDispatcher::class)->tick()['started']);

        $this->assertSame(DeploymentStatus::InProgress, $one->deployment->fresh()->status);
        $this->assertSame(DeploymentStatus::Waiting, $two->deployment->fresh()->status, 'Cap 1 is full again.');
        $this->assertSame(1, $queue->positionOf($two->deployment->fresh()));
    }

    public function test_host_lock_prevents_a_second_tick_from_starting_the_same_row(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $busy = $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-locked');
        $this->fakeCoolify();

        $outcome = app(WaitingDeployQueue::class)->request($site, WaitingDeployAction::Redeploy);
        $busy->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();

        // Another worker is draining this host right now.
        $lock = Cache::lock(WaitingDeployQueue::lockKey($site), 60);
        $this->assertTrue($lock->get());

        $this->assertSame(0, app(WaitingDeployDispatcher::class)->tick()['started']);
        $this->assertSame(DeploymentStatus::Waiting, $outcome->deployment->fresh()->status);

        $lock->release();

        $this->assertSame(1, app(WaitingDeployDispatcher::class)->tick()['started']);
        $this->assertSame(0, app(WaitingDeployDispatcher::class)->tick()['started']);
        $this->assertSame(1, $this->deployPosts(), 'Exactly one Coolify deploy for one waiting row.');
    }

    public function test_operator_cancels_a_waiting_deploy_from_the_site_and_the_widget(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $busy = $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-cancel');
        $other = $this->site('app-cancel-2');
        $this->fakeCoolify();
        $operator = $this->operator();
        $queue = app(WaitingDeployQueue::class);

        $row = $queue->request($site, WaitingDeployAction::Redeploy, [], $operator)->deployment;
        $widgetRow = $queue->request($other, WaitingDeployAction::Redeploy, [], $operator)->deployment;

        $this->actingAs($operator)
            ->post(route('ops.sites.deployments.cancel-waiting', [$site, $row]))
            ->assertRedirect()
            ->assertSessionHas('status', __('site_ops.queue.cancel_done', ['name' => $site->name]));

        $this->actingAs($operator)
            ->postJson(route('ops.jobs.deployments.cancel', $widgetRow))
            ->assertOk()
            ->assertJsonPath('deployment.status', 'cancelled');

        foreach ([$row, $widgetRow] as $cancelled) {
            $fresh = $cancelled->fresh();
            $this->assertSame(DeploymentStatus::Cancelled, $fresh->status);
            $this->assertNotNull($fresh->finished_at);
            $this->assertSame(__('site_ops.queue.cancelled'), $fresh->error_message);
        }
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.deploy_queue_cancelled',
            'subject_id' => $site->id,
            'actor_user_id' => $operator->id,
        ]);

        $busy->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        $this->assertSame(0, app(WaitingDeployDispatcher::class)->tick()['started']);
        $this->assertSame(0, $this->deployPosts(), 'A cancelled row never reaches Coolify.');
    }

    public function test_viewer_cannot_cancel_a_waiting_deploy(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-viewer');
        $row = app(WaitingDeployQueue::class)->request($site, WaitingDeployAction::Redeploy)->deployment;

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.deployments.cancel-waiting', [$site, $row]))
            ->assertForbidden();

        $this->assertSame(DeploymentStatus::Waiting, $row->fresh()->status);
    }

    public function test_follow_head_when_busy_is_queued_not_an_error_and_applies_its_side_effects_at_start(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $busy = $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-follow');
        $site->forceFill(['coolify_pinned_sha' => 'deadbeef', 'coolify_auto_deploy' => false])->save();
        $this->fakeCoolify();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.follow-head', $site))
            ->assertRedirect()
            ->assertSessionMissing('error')
            ->assertSessionHas('status', __('site_ops.queue.queued', ['running' => 1, 'position' => 1]));

        Http::assertNothingSent();
        $fresh = $site->fresh();
        $this->assertSame('deadbeef', $fresh->coolify_pinned_sha, 'Unpin waits for the start.');
        $this->assertFalse($fresh->coolify_auto_deploy);
        $this->assertSame(WaitingDeployAction::FollowHead, $site->deployments()->sole()->queue_action);

        $busy->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        $this->assertSame(1, app(WaitingDeployDispatcher::class)->tick()['started']);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/applications/app-follow')
            && ($request->data()['git_commit_sha'] ?? null) === 'HEAD'
            && ($request->data()['is_auto_deploy_enabled'] ?? null) === true);
        $fresh = $site->fresh();
        $this->assertNull($fresh->coolify_pinned_sha);
        $this->assertTrue($fresh->coolify_auto_deploy);
        $this->assertSame(DeploymentStatus::InProgress, $site->deployments()->sole()->status);
    }

    public function test_a_second_click_returns_the_row_already_waiting(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-twice');
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('ops.sites.deploy', $site));
        $this->actingAs($operator)->post(route('ops.sites.deploy', $site))
            ->assertSessionHas('status', __('site_ops.queue.already_queued', ['running' => 1, 'position' => 1]));

        $this->assertSame(1, $site->deployments()->waiting()->count());
    }

    public function test_a_failed_start_marks_the_row_failed_with_the_reason(): void
    {
        $site = $this->site('app-broken');
        $row = $this->waitingRow($site, WaitingDeployAction::Redeploy);
        $this->fakeCoolify(deployStatus: 422);

        $result = app(WaitingDeployDispatcher::class)->tick();

        $this->assertSame(1, $result['failed']);
        $fresh = $row->fresh();
        $this->assertSame(DeploymentStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertStringStartsWith(
            trim(explode(':error', __('site_ops.queue.start_failed'))[0]),
            (string) $fresh->error_message,
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.deploy_queue_failed', 'subject_id' => $site->id]);
    }

    public function test_a_transient_start_error_keeps_waiting_until_the_attempt_budget_runs_out(): void
    {
        config()->set('ops.coolify.deploy.waiting_max_attempts', 2);
        $site = $this->site('app-flaky');
        $row = $this->waitingRow($site, WaitingDeployAction::Redeploy);
        $this->fakeCoolify(deployStatus: 503);

        app(WaitingDeployDispatcher::class)->tick();
        $this->assertSame(DeploymentStatus::Waiting, $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->queue_attempts);

        app(WaitingDeployDispatcher::class)->tick();
        $this->assertSame(DeploymentStatus::Failed, $row->fresh()->status);
    }

    public function test_expired_deleted_and_already_satisfied_rows_are_closed_without_a_deploy(): void
    {
        $this->fakeCoolify();

        $old = $this->waitingRow($this->site('app-old'), WaitingDeployAction::Redeploy);
        $old->forceFill(['queued_at' => now()->subHours(3)])->save();

        $goneSite = $this->site('app-gone');
        $gone = $this->waitingRow($goneSite, WaitingDeployAction::Redeploy);
        $goneSite->delete();

        $doneSite = $this->site('app-done');
        $done = $this->waitingRow($doneSite, WaitingDeployAction::UpdateHead);
        $this->travel(1)->minutes();
        Deployment::factory()->create([
            'site_id' => $doneSite->id,
            'status' => DeploymentStatus::Finished,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        app(WaitingDeployDispatcher::class)->tick();

        $this->assertSame(DeploymentStatus::Failed, $old->fresh()->status);
        $this->assertSame(__('site_ops.queue.expired', ['minutes' => 120]), $old->fresh()->error_message);
        $this->assertSame(DeploymentStatus::Cancelled, $gone->fresh()->status);
        $this->assertSame(__('site_ops.queue.site_gone'), $gone->fresh()->error_message);
        $this->assertSame(DeploymentStatus::Cancelled, $done->fresh()->status);
        $this->assertSame(__('site_ops.queue.already_deployed'), $done->fresh()->error_message);
        $this->assertSame(0, $this->deployPosts());
    }

    public function test_bulk_sweep_keeps_its_own_pacing_and_is_never_queued_in_plane(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $this->openBuild($this->site('app-foreign'));
        $sites = [$this->site('app-bulk-1'), $this->site('app-bulk-2')];
        $this->fakeCoolify();

        $job = OpsBackgroundJob::query()->create([
            'type' => 'sites.bulk_deploy',
            'title' => 'sites.bulk_deploy',
            'status' => 'queued',
            'progress' => 0,
            'payload' => ['site_ids' => array_map(static fn (Site $site) => $site->id, $sites)],
            'actor_user_id' => $this->operator()->id,
        ]);

        $message = app(OpsJobRunner::class)->run($job);

        $this->assertStringContainsString(__('ops.bulk.waiting', ['waiting' => 2]), $message);
        $this->assertSame(0, Deployment::query()->waiting()->count(), 'A sweep never double-queues in Plane.');
        $this->assertSame(0, $this->deployPosts());
    }

    public function test_channel_switch_behind_a_full_cap_waits_and_cancel_releases_the_site(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-switch');
        $site->forceFill(['status' => SiteStatus::Deploying, 'desired_channel' => Channel::Beta])->save();
        $operator = $this->operator();

        (new SwitchSiteChannelJob((string) $site->id, $operator->id))->handle(app(ChannelSwitcher::class));

        Http::assertNothingSent();
        $row = $site->deployments()->sole();
        $this->assertSame(DeploymentStatus::Waiting, $row->status);
        $this->assertSame(DeploymentTrigger::ChannelSwitch, $row->trigger);
        $this->assertSame(Channel::Beta, $row->channel);
        $this->assertSame(SiteStatus::Deploying, $site->fresh()->status, 'Waiting is not a failure.');

        app(WaitingDeployQueue::class)->cancel($row, $operator);

        $fresh = $site->fresh();
        $this->assertSame(SiteStatus::Active, $fresh->status);
        $this->assertNull($fresh->desired_channel);
    }

    public function test_jobs_widget_and_deployments_tab_show_the_waiting_row_with_its_place(): void
    {
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
        $this->openBuild($this->site('app-busy'));
        $site = $this->site('app-widget');
        $row = app(WaitingDeployQueue::class)->request($site, WaitingDeployAction::Pin, ['ref' => 'abc1234'])->deployment;
        Http::fake([
            'https://coolify.example/api/v1/deployments' => Http::response([], 200),
            '*' => Http::response([], 404),
        ]);

        $response = $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonPath('queue.waiting', 1);

        $widget = collect($response->json('deployments'))->firstWhere('deployment_id', $row->id);
        $this->assertNotNull($widget);
        $this->assertSame('queued', $widget['status']);
        $this->assertTrue($widget['actions']['cancel']);
        $this->assertFalse($widget['actions']['force_start']);
        $this->assertSame(__('site_ops.queue.widget_position', ['position' => 1, 'depth' => 1]), $widget['queue_label']);
        $this->assertStringContainsString(__('site_ops.queue.summary', ['count' => 1]), (string) $response->json('queue.label'));

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('site_ops.queue.tab_title'), false)
            ->assertSee(route('ops.sites.deployments.cancel-waiting', [$site, $row]), false)
            ->assertSee(e(__('site_ops.queue.position', ['position' => 1])), false);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.deployments.show', [$site, $row]))
            ->assertOk();
    }

    private function site(string $appUuid): Site
    {
        return Site::factory()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => $appUuid,
            'coolify_connection_id' => $this->connection->id,
        ]);
    }

    private function openBuild(Site $site): Deployment
    {
        return Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'coolify_deployment_uuid' => 'open-'.$site->coolify_app_uuid,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);
    }

    private function waitingRow(Site $site, WaitingDeployAction $action, array $payload = []): Deployment
    {
        return Deployment::factory()->create([
            'site_id' => $site->id,
            'trigger' => $action->trigger(),
            'status' => DeploymentStatus::Waiting,
            'queue_action' => $action,
            'queue_payload' => $payload,
            'queued_at' => now(),
        ]);
    }

    private function fakeCoolify(int $deployStatus = 200): void
    {
        Http::fake(function (Request $request) use ($deployStatus) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/applications/')) {
                $uuid = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');

                return Http::response([
                    'uuid' => $uuid,
                    'build_pack' => 'dockercompose',
                    'is_auto_deploy_enabled' => $request->data()['is_auto_deploy_enabled'] ?? false,
                    'settings' => ['is_auto_deploy_enabled' => $request->data()['is_auto_deploy_enabled'] ?? false],
                    'git_commit_sha' => $request->data()['git_commit_sha'] ?? null,
                ], 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                if ($deployStatus !== 200) {
                    return Http::response(['message' => 'Deploy refused by Coolify.'], $deployStatus);
                }
                $this->deploySeq++;

                return Http::response(['deployments' => [['deployment_uuid' => 'dep-wait-'.$this->deploySeq]]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });
    }

    private function deployPosts(): int
    {
        return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/deploy'))->count();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
