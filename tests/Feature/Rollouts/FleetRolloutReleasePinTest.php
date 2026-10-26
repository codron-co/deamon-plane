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
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\GitHub\CiBranchHeads;
use App\Services\Rollouts\FleetRolloutService;
use App\Services\Sites\CoolifyDeploySettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Build once, run many pins every Deamon site. A CI rollout still has to reach the
 * fleet: a pin to a commit the channel builder finished (a release pin, from Plane or
 * the host's release.sh) follows the channel; an operator hold (Commite geç) and a pin
 * to any other commit are skipped.
 */
class FleetRolloutReleasePinTest extends TestCase
{
    use RefreshDatabase;

    private const CMS = 'codron-co/deamon';

    private const GREEN = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const RELEASED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const UNBUILT = 'cccccccccccccccccccccccccccccccccccccccc';

    private CoolifyConnection $connection;

    /** @var array<string, string> app uuid => commit the fake Coolify app is on */
    private array $appShas = [];

    private bool $builderDown = false;

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
            // Several deploys of one site in a row; none finishes in the fake.
            'ops.coolify.deploy.max_concurrent_per_server' => 10,
        ]);

        app(CiBranchHeads::class)->recordPush(self::CMS, 'beta', self::GREEN);
        $this->connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'release-pin-token',
        ]);
        $this->fakeCoolify();
    }

    public function test_release_pins_follow_the_channel_and_holds_or_unbuilt_pins_do_not(): void
    {
        $unpinned = $this->site('unpinned');
        $released = $this->site('released', ['coolify_pinned_sha' => self::RELEASED]);
        $older = $this->site('older-release', ['coolify_pinned_sha' => self::OLDER]);
        $held = $this->site('held', ['coolify_pinned_sha' => self::RELEASED, 'coolify_pin_held' => true]);
        $unbuilt = $this->site('unbuilt', ['coolify_pinned_sha' => self::UNBUILT]);
        $manual = $this->site('manual-gate', ['coolify_pinned_sha' => self::RELEASED, 'deploy_gate' => DeployGate::Coolify]);

        $rollouts = app(FleetRolloutService::class);
        $eligible = $rollouts->eligibleSites(Channel::Beta)->pluck('slug')->sort()->values()->all();

        $this->assertSame(['older-release', 'released', 'unpinned'], $eligible);
        $this->assertTrue($rollouts->followsChannel($unpinned));
        $this->assertTrue($rollouts->followsChannel($released));
        $this->assertTrue($rollouts->followsChannel($older));
        $this->assertFalse($rollouts->followsChannel($held));
        $this->assertFalse($rollouts->followsChannel($unbuilt));

        $rollout = $this->rollout();
        $this->assertTrue($rollouts->qualifies($released, $rollout));
        $this->assertFalse($rollouts->qualifies($held, $rollout));
        $this->assertFalse($rollouts->qualifies($unbuilt, $rollout));
        $this->assertFalse($rollouts->qualifies($manual, $rollout));
    }

    public function test_a_rollout_moves_release_pinned_sites_to_the_green_commit_and_leaves_holds_alone(): void
    {
        $canary = $this->site('canary', ['coolify_pinned_sha' => self::RELEASED, 'deploy_canary' => true]);
        $held = $this->site('held', ['coolify_pinned_sha' => self::OLDER, 'coolify_pin_held' => true, 'deploy_canary' => true]);

        $rollout = app(FleetRolloutService::class)->startForGreenCommit(Channel::Beta, self::GREEN);
        $this->assertInstanceOf(FleetRollout::class, $rollout);

        app(FleetRolloutService::class)->advance($rollout);

        $this->assertSame([(string) $canary->id], $rollout->fresh()->stage('canary')['site_ids']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/app-canary')
            && ($r->data()['git_commit_sha'] ?? null) === self::GREEN);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'app-held'));

        // The rollout's own pin is a release pin, not a hold.
        $this->assertSame(self::GREEN, $canary->fresh()->coolify_pinned_sha);
        $this->assertFalse($canary->fresh()->coolify_pin_held);
        $this->assertTrue($held->fresh()->coolify_pin_held);
    }

    public function test_a_release_pinned_channel_alone_starts_a_rollout(): void
    {
        $this->site('released', ['coolify_pinned_sha' => self::RELEASED]);

        $this->assertInstanceOf(FleetRollout::class, app(FleetRolloutService::class)->startForGreenCommit(Channel::Beta, self::GREEN));
    }

    public function test_an_unreadable_builder_skips_pinned_sites_but_not_unpinned_ones(): void
    {
        $this->site('unpinned');
        $released = $this->site('released', ['coolify_pinned_sha' => self::RELEASED]);
        $this->builderDown = true;

        $rollouts = app(FleetRolloutService::class);

        $this->assertSame(['unpinned'], $rollouts->eligibleSites(Channel::Beta)->pluck('slug')->all());
        $this->assertFalse($rollouts->followsChannel($released));
    }

    public function test_a_channel_without_an_image_builder_keeps_skipping_every_pin(): void
    {
        config(['ops.deamon.image_builders' => ['alpha' => '', 'beta' => '']]);
        $this->site('unpinned');
        $released = $this->site('released', ['coolify_pinned_sha' => self::RELEASED]);

        $rollouts = app(FleetRolloutService::class);

        $this->assertSame(['unpinned'], $rollouts->eligibleSites(Channel::Beta)->pluck('slug')->all());
        $this->assertFalse($rollouts->followsChannel($released));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deployments/applications/'));
    }

    public function test_commite_gec_holds_and_deploy_newest_image_releases(): void
    {
        $site = $this->site('shop', ['coolify_pinned_sha' => self::RELEASED]);
        $settings = app(CoolifyDeploySettings::class);

        $settings->pin($site, substr(self::OLDER, 0, 8));
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
        $this->assertTrue($site->fresh()->coolify_pin_held);
        $this->assertFalse(app(FleetRolloutService::class)->followsChannel($site->fresh()));

        // Tekrar deploy stays on the held image and keeps the hold.
        $settings->redeploy($site->fresh());
        $this->assertTrue($site->fresh()->coolify_pin_held);

        $settings->updateToHead($site->fresh());
        $this->assertSame(self::GREEN, $site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_pin_held);
        $this->assertTrue(app(FleetRolloutService::class)->followsChannel($site->fresh()));
    }

    public function test_mirrored_pins_keep_a_hold_on_the_same_commit_and_drop_it_on_another(): void
    {
        $site = $this->site('shop', ['coolify_pinned_sha' => self::OLDER, 'coolify_pin_held' => true]);

        // The Coolify sync reads the same pin back: still held.
        CoolifyDeploySettings::mirrorFromApplication($site, $this->application(self::OLDER));
        $this->assertTrue($site->fresh()->coolify_pin_held);

        // release.sh pinned the site directly through the Coolify API and mirrors it.
        CoolifyDeploySettings::rememberPin($site, self::RELEASED);
        $this->assertSame(self::RELEASED, $site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_pin_held);
        $this->assertTrue(app(FleetRolloutService::class)->followsChannel($site->fresh()));

        // Someone pinned it by hand in Coolify to a commit without an image.
        CoolifyDeploySettings::mirrorFromApplication($site, $this->application(self::UNBUILT));
        $this->assertFalse($site->fresh()->coolify_pin_held);
        $this->assertFalse(app(FleetRolloutService::class)->followsChannel($site->fresh()));

        // Back on HEAD: no pin, no hold.
        CoolifyDeploySettings::mirrorFromApplication($site, $this->application(CoolifyApplication::HEAD_REF));
        $this->assertNull($site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_pin_held);
    }

    public function test_the_migration_marks_existing_commite_gec_pins_as_holds(): void
    {
        $pinnedByHand = $this->site('by-hand', ['coolify_pinned_sha' => self::OLDER]);
        $pinnedByHand->auditLogs()->create(['action' => 'site.git_pinned', 'after' => ['git_commit_sha' => self::OLDER]]);
        $releasedLater = $this->site('released-later', ['coolify_pinned_sha' => self::RELEASED]);
        $releasedLater->auditLogs()->create(['action' => 'site.git_pinned', 'after' => ['git_commit_sha' => self::OLDER]]);
        $this->travel(1)->minutes();
        $releasedLater->auditLogs()->create(['action' => 'site.git_update_head', 'after' => ['git_commit_sha' => self::RELEASED]]);
        $mirrored = $this->site('mirrored', ['coolify_pinned_sha' => self::RELEASED]);

        $migration = require database_path('migrations/2026_10_26_210000_add_coolify_pin_held_to_sites_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue($pinnedByHand->fresh()->coolify_pin_held);
        $this->assertFalse($releasedLater->fresh()->coolify_pin_held);
        $this->assertFalse($mirrored->fresh()->coolify_pin_held);
    }

    private function application(string $sha): CoolifyApplication
    {
        return CoolifyApplication::fromArray([
            'uuid' => 'app-shop',
            'build_pack' => 'dockercompose',
            'git_commit_sha' => $sha,
            'is_auto_deploy_enabled' => false,
            'settings' => ['is_auto_deploy_enabled' => false],
        ]);
    }

    private function rollout(): FleetRollout
    {
        return FleetRollout::query()->create([
            'channel' => Channel::Beta,
            'sha' => self::GREEN,
            'status' => FleetRolloutStatus::Canary,
            'started_at' => now(),
            'stage_started_at' => now(),
            'summary' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(string $slug, array $attributes = []): Site
    {
        $site = Site::factory()->withSecrets()->create(array_merge([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'primary_domain' => $slug.'.example.test',
            'status' => SiteStatus::Active,
            'channel' => Channel::Beta,
            'coolify_app_uuid' => 'app-'.$slug,
            'coolify_connection_id' => $this->connection->id,
            'deploy_gate' => DeployGate::Ci,
            'coolify_auto_deploy' => false,
        ], $attributes));
        $this->appShas['app-'.$slug] = (string) ($attributes['coolify_pinned_sha'] ?? 'HEAD');

        return $site;
    }

    private function fakeCoolify(): void
    {
        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }

            $url = $request->url();
            $method = $request->method();
            $finished = now()->utc()->subMinutes(10)->toIso8601ZuluString();

            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-beta')) {
                if ($this->builderDown) {
                    return Http::response(['message' => 'down'], 500);
                }

                return Http::response(['count' => 4, 'deployments' => [
                    ['id' => 14, 'commit' => self::GREEN, 'status' => 'finished', 'finished_at' => $finished],
                    ['id' => 13, 'commit' => self::UNBUILT, 'status' => 'failed', 'finished_at' => $finished],
                    ['id' => 12, 'commit' => self::RELEASED, 'status' => 'finished', 'finished_at' => $finished],
                    ['id' => 11, 'commit' => self::OLDER, 'status' => 'finished', 'finished_at' => $finished],
                ]], 200);
            }
            if (preg_match('#/applications/(app-[a-z-]+)$#', $url, $match) === 1 && in_array($method, ['GET', 'PATCH'], true)) {
                $uuid = $match[1];
                if ($method === 'PATCH' && array_key_exists('git_commit_sha', $request->data())) {
                    $this->appShas[$uuid] = (string) $request->data()['git_commit_sha'];
                }

                return Http::response([
                    'uuid' => $uuid,
                    'build_pack' => 'dockercompose',
                    'git_commit_sha' => $this->appShas[$uuid] ?? 'HEAD',
                    'is_auto_deploy_enabled' => false,
                    'settings' => ['is_auto_deploy_enabled' => false],
                ], 200);
            }
            if ($method === 'POST' && str_contains($url, 'coolify.example/api/v1/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-'.count(Http::recorded())]]], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });
    }
}
