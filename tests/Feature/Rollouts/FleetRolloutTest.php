<?php

namespace Tests\Feature\Rollouts;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\FleetRolloutStatus;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Jobs\KickStalledFleetRolloutsJob;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Services\GitHub\CiBranchHeads;
use App\Services\Rollouts\FleetRolloutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FleetRolloutTest extends TestCase
{
    use RefreshDatabase;

    private const CMS = 'codron-co/deamon';

    private const SHA = 'green111';

    private CoolifyConnection $connection;

    /** @var array<string, string> app uuid → agent health answer ('ok' | 'down') */
    private array $health = [];

    private int $deployCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake([AdvanceFleetRolloutJob::class, PollDeploymentJob::class]);
        config([
            'ops.deamon.repository' => 'https://github.com/codron-co/deamon.git',
            'ops.ci.canary_health_attempts' => 2,
        ]);

        $this->connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'rollout-token',
        ]);

        app(CiBranchHeads::class)->recordPush(self::CMS, 'main', self::SHA);
        $this->fakeHttp();
    }

    public function test_canary_passes_then_the_rest_of_the_gated_fleet_fans_out(): void
    {
        $canary = $this->site('canary', canary: true);
        $alpha = $this->site('regular-a');
        $beta = $this->site('regular-b');
        $coolifyGated = $this->site('coolify-gated', gate: DeployGate::Coolify);
        $pinned = $this->site('pinned', attributes: ['coolify_pinned_sha' => 'abc1234']);
        $otherChannel = $this->site('beta-channel', attributes: ['channel' => Channel::Beta]);

        $rollout = $this->start();
        $this->assertSame(FleetRolloutStatus::Canary, $rollout->status);

        // Tick 1: only the canary is asked to build, without force.
        $this->assertNotNull($this->advance($rollout));
        $this->assertDeployedApps(['app-canary']);
        $deployment = Deployment::query()->where('site_id', $canary->id)->sole();
        $this->assertSame(DeploymentTrigger::CiRollout, $deployment->trigger);
        $this->assertSame(DeploymentStatus::InProgress, $deployment->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deploy?')
            && ! str_contains($request->url(), 'force='));

        // Tick 2: build still running → keep waiting, nothing else deploys.
        $this->assertNotNull($this->advance($rollout));
        $this->assertDeployedApps(['app-canary']);

        // Tick 3: build finished + agent health OK → fan out to the rest and finish.
        $deployment->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        $this->assertNull($this->advance($rollout));

        $rollout->refresh();
        $this->assertSame(FleetRolloutStatus::Done, $rollout->status);
        $this->assertNotNull($rollout->finished_at);
        $this->assertDeployedApps(['app-canary', 'app-regular-a', 'app-regular-b']);
        $this->assertSame(['targets' => 1, 'deployed' => 1, 'healthy' => 1, 'failed' => 0, 'pending' => 0], $rollout->stageCounts('canary'));
        $this->assertSame(2, $rollout->stageCounts('fanout')['deployed']);
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.done')->exists());
        $this->assertTrue($alpha->auditLogs()->where('action', 'site.ci_rollout_deployed')->exists());

        foreach ([$coolifyGated, $pinned, $otherChannel] as $untouched) {
            $this->assertSame(0, $untouched->deployments()->count(), $untouched->slug.' must not deploy');
        }
        foreach ([$alpha, $beta] as $site) {
            $this->assertSame(DeploymentTrigger::CiRollout, $site->deployments()->sole()->trigger);
        }
    }

    public function test_no_canary_goes_straight_to_fanout(): void
    {
        $this->site('regular-a');
        $this->site('regular-b');

        $rollout = $this->start();
        $this->assertNull($this->advance($rollout));

        $this->assertSame(FleetRolloutStatus::Done, $rollout->fresh()->status);
        $this->assertDeployedApps(['app-regular-a', 'app-regular-b']);
    }

    public function test_failed_canary_build_halts_and_nothing_else_deploys(): void
    {
        $canary = $this->site('canary', canary: true);
        $regular = $this->site('regular-a');

        $rollout = $this->start();
        $this->advance($rollout);
        Deployment::query()->where('site_id', $canary->id)->sole()
            ->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now(), 'error_message' => 'compose up failed'])
            ->save();

        $this->assertNull($this->advance($rollout));

        $rollout->refresh();
        $this->assertSame(FleetRolloutStatus::Halted, $rollout->status);
        $this->assertStringContainsString($canary->name, (string) $rollout->halted_reason);
        $this->assertSame('canary_failed', $rollout->summary['halt']['code']);
        $this->assertSame(0, $regular->deployments()->count());
        $this->assertDeployedApps(['app-canary']);
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.canary_failed')->exists());
        $this->assertTrue($canary->auditLogs()->where('action', 'site.ci_rollout_canary_failed')->exists());
    }

    public function test_unhealthy_canary_halts_after_the_configured_attempts(): void
    {
        $canary = $this->site('canary', canary: true);
        $regular = $this->site('regular-a');
        $this->health['canary'] = 'down';

        $rollout = $this->start();
        $this->advance($rollout);
        Deployment::query()->where('site_id', $canary->id)->sole()
            ->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();

        $this->assertNotNull($this->advance($rollout), 'first failed health check waits');
        $this->assertSame(FleetRolloutStatus::Canary, $rollout->fresh()->status);

        $this->assertNull($this->advance($rollout));
        $this->assertSame(FleetRolloutStatus::Halted, $rollout->fresh()->status);
        $this->assertSame(0, $regular->deployments()->count());
    }

    public function test_canary_timeout_halts(): void
    {
        $canary = $this->site('canary', canary: true);
        $regular = $this->site('regular-a');

        $rollout = $this->start();
        $this->advance($rollout);

        $this->travel(31)->minutes();
        $this->assertNull($this->advance($rollout));

        $rollout->refresh();
        $this->assertSame(FleetRolloutStatus::Halted, $rollout->status);
        $this->assertSame('timeout', $rollout->summary['halt']['code']);
        $this->assertContains($canary->name, $rollout->summary['halt']['sites']);
        $this->assertSame(0, $regular->deployments()->count());
    }

    public function test_a_newer_push_supersedes_the_rollout_before_its_next_stage(): void
    {
        $canary = $this->site('canary', canary: true);
        $regular = $this->site('regular-a');

        $rollout = $this->start();
        $this->advance($rollout);
        Deployment::query()->where('site_id', $canary->id)->sole()
            ->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();

        app(CiBranchHeads::class)->recordPush(self::CMS, 'main', 'newer222');

        $this->assertNull($this->advance($rollout));
        $this->assertSame(FleetRolloutStatus::Superseded, $rollout->fresh()->status);
        $this->assertSame(0, $regular->deployments()->count());
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.superseded')->exists());
    }

    public function test_a_site_that_left_the_gate_mid_rollout_is_skipped(): void
    {
        config(['ops.ci.fanout_batch' => 1]);
        $this->site('regular-a');
        $leaving = $this->site('regular-b');

        $rollout = $this->start();
        $this->assertNotNull($this->advance($rollout));
        $leaving->forceFill(['coolify_pinned_sha' => 'pinned123'])->save();

        $this->assertNull($this->advance($rollout));

        $this->assertDeployedApps(['app-regular-a']);
        $this->assertSame(FleetRolloutStatus::Done, $rollout->fresh()->status);
        $this->assertContains((string) $leaving->id, $rollout->fresh()->stage('fanout')['skipped']);
    }

    public function test_fanout_continues_in_batches(): void
    {
        config(['ops.ci.fanout_batch' => 1]);
        $this->site('regular-a');
        $this->site('regular-b');

        $rollout = $this->start();
        $this->assertNotNull($this->advance($rollout));
        $this->assertSame(FleetRolloutStatus::Fanout, $rollout->fresh()->status);
        $this->assertDeployedApps(['app-regular-a']);

        // The first build still holds the host deploy gate: the second site waits.
        $this->assertNotNull($this->advance($rollout));
        $this->assertDeployedApps(['app-regular-a']);

        Deployment::query()->update(['status' => DeploymentStatus::Finished, 'finished_at' => now()]);
        $this->assertNull($this->advance($rollout));
        $this->assertDeployedApps(['app-regular-a', 'app-regular-b']);
        $this->assertSame(FleetRolloutStatus::Done, $rollout->fresh()->status);
    }

    public function test_the_advance_job_queues_the_next_tick_while_waiting(): void
    {
        $this->site('canary', canary: true);
        $rollout = $this->start();

        // What the worker does when it picks the job up (unique until processing).
        $job = new AdvanceFleetRolloutJob($rollout->id);
        (new UniqueLock(app(Cache::class)))->release($job);
        $job->handle(app(FleetRolloutService::class));

        Queue::assertPushed(AdvanceFleetRolloutJob::class, 2); // start + next tick
    }

    public function test_watchdog_kicks_stalled_open_rollouts_only(): void
    {
        $this->site('canary', canary: true);
        $open = $this->start();
        $done = FleetRollout::query()->create([
            'channel' => Channel::Main,
            'sha' => 'old000',
            'status' => FleetRolloutStatus::Done,
            'started_at' => now(),
        ]);
        FleetRollout::query()->whereKey([$open->id, $done->id])->update(['updated_at' => now()->subMinutes(10)]);
        Queue::fake([AdvanceFleetRolloutJob::class]);

        (new KickStalledFleetRolloutsJob)->handle();

        Queue::assertPushed(AdvanceFleetRolloutJob::class, fn (AdvanceFleetRolloutJob $job): bool => $job->rolloutId === $open->id);
        Queue::assertNotPushed(AdvanceFleetRolloutJob::class, fn (AdvanceFleetRolloutJob $job): bool => $job->rolloutId === $done->id);
    }

    private function start(): FleetRollout
    {
        $rollout = app(FleetRolloutService::class)->startForGreenCommit(Channel::Main, self::SHA);
        $this->assertNotNull($rollout);

        return $rollout;
    }

    private function advance(FleetRollout $rollout): ?int
    {
        return app(FleetRolloutService::class)->advance($rollout);
    }

    /**
     * @param  list<string>  $apps
     */
    private function assertDeployedApps(array $apps): void
    {
        $sent = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $sent[] = (string) ($query['uuid'] ?? '');
            }
        }
        sort($sent);
        sort($apps);

        $this->assertSame($apps, $sent);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(string $slug, bool $canary = false, DeployGate $gate = DeployGate::Ci, array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'primary_domain' => $slug.'.example.test',
            'agent_base_url' => 'https://'.$slug.'.example.test',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'app-'.$slug,
            'coolify_connection_id' => $this->connection->id,
            'deploy_gate' => $gate,
            'deploy_canary' => $canary,
        ], $attributes));
    }

    private function fakeHttp(): void
    {
        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }

            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, 'coolify.example/api/v1/deploy')) {
                $this->deployCounter++;

                return Http::response(['deployments' => [['deployment_uuid' => 'dep-'.$this->deployCounter]]], 200);
            }

            if (preg_match('~https://([a-z0-9-]+)\.example\.test/internal/control/v1/health~', $url, $match) === 1) {
                return ($this->health[$match[1]] ?? 'ok') === 'ok'
                    ? Http::response(['deamon_version' => '1.2.40', 'queue_ok' => true], 200)
                    : Http::response(['message' => 'down'], 500);
            }

            return Http::response(['error' => 'unexpected '.$request->method().' '.$url], 404);
        });
    }
}
