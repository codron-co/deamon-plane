<?php

namespace Tests\Feature\Rollouts;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\FleetRolloutStatus;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Services\GitHub\CiBranchHeads;
use App\Services\Rollouts\FleetRolloutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A CI rollout of a channel that runs built images deploys the green commit only
 * once the channel builder finished it; until then the canary waits (no failure).
 */
class FleetRolloutBuiltImageTest extends TestCase
{
    use RefreshDatabase;

    private const CMS = 'codron-co/deamon';

    private const SHA = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** @var list<array<string, mixed>> */
    private array $builds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake([AdvanceFleetRolloutJob::class, PollDeploymentJob::class]);
        config([
            'ops.deamon.repository' => 'https://github.com/codron-co/deamon.git',
            'ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => 'builder-beta'],
            'ops.deamon.image_builds_cache_seconds' => 0,
        ]);

        app(CiBranchHeads::class)->recordPush(self::CMS, 'main', self::SHA);
        $this->fakeHttp();
    }

    public function test_canary_waits_for_the_image_then_deploys_the_green_commit(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'rollout-token',
        ]);
        Site::factory()->withSecrets()->create([
            'slug' => 'canary',
            'name' => 'Canary',
            'primary_domain' => 'canary.example.test',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'app-canary',
            'coolify_connection_id' => $connection->id,
            'deploy_gate' => DeployGate::Ci,
            'deploy_canary' => true,
        ]);

        $rollout = app(FleetRolloutService::class)->startForGreenCommit(Channel::Main, self::SHA);
        $this->assertInstanceOf(FleetRollout::class, $rollout);

        // The builder is still on it: nothing deployed, the canary stays pending.
        $this->builds = [['id' => 2, 'commit' => 'HEAD', 'status' => 'in_progress']];
        $this->assertNotNull(app(FleetRolloutService::class)->advance($rollout));
        $this->assertSame(FleetRolloutStatus::Canary, $rollout->fresh()->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
        $this->assertCount(1, $rollout->fresh()->stage('canary')['pending'] ?? []);

        // Built: the next tick pins the green commit and deploys it.
        $this->builds = [['id' => 2, 'commit' => self::SHA, 'status' => 'finished', 'finished_at' => now()->utc()->subMinutes(5)->toIso8601ZuluString()]];
        $this->assertNotNull(app(FleetRolloutService::class)->advance($rollout));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/app-canary')
            && ($r->data()['git_commit_sha'] ?? null) === self::SHA
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?') && str_contains($r->url(), 'uuid=app-canary'));
        $this->assertSame([], $rollout->fresh()->stage('canary')['pending'] ?? null);
    }

    private function fakeHttp(): void
    {
        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }

            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-beta')) {
                return Http::response(['count' => count($this->builds), 'deployments' => $this->builds], 200);
            }
            if (in_array($method, ['GET', 'PATCH'], true) && str_ends_with($url, '/applications/app-canary')) {
                return Http::response(['uuid' => 'app-canary', 'build_pack' => 'dockercompose', 'git_commit_sha' => 'HEAD'], 200);
            }
            if ($method === 'POST' && str_contains($url, 'coolify.example/api/v1/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-1']]], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });
    }
}
