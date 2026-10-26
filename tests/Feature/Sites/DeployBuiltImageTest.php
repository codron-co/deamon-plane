<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\FleetRolloutStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\CoolifyConnection;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\BuiltImageException;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\CoolifyDeploySettings;
use App\Services\Sites\SiteLifecycle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every Plane path that deploys a Deamon site targets a commit its channel's image
 * builder finished and never turns Coolify auto-deploy on (build once, run many).
 */
class DeployBuiltImageTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'site-app-1';

    private const LATEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const UNBUILT = 'cccccccccccccccccccccccccccccccccccccccc';

    private const ALPHA_LATEST = 'dddddddddddddddddddddddddddddddddddddddd';

    /** Commit the fake Coolify app is pinned to (`HEAD` = follows the branch). */
    private string $appSha = 'HEAD';

    /** @var list<array<string, mixed>> */
    private array $betaBuilds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();
        config(['ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => 'builder-beta']]);

        $this->betaBuilds = [
            ['id' => 12, 'commit' => 'HEAD', 'status' => 'in_progress'],
            ['id' => 11, 'commit' => self::UNBUILT, 'status' => 'failed', 'finished_at' => $this->ago(5)],
            ['id' => 10, 'commit' => self::LATEST, 'status' => 'finished', 'finished_at' => $this->ago(15)],
            ['id' => 9, 'commit' => self::OLDER, 'status' => 'finished', 'finished_at' => $this->ago(90)],
        ];
        $this->fakeCoolify();
    }

    public function test_redeploy_of_a_site_on_head_moves_it_to_the_newest_build_before_deploying(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertPinnedBeforeDeploy(self::LATEST);
        $this->assertSame(self::LATEST, $site->fresh()->coolify_pinned_sha);
    }

    public function test_redeploy_keeps_a_pin_that_is_a_built_image(): void
    {
        $this->appSha = self::OLDER;
        $site = $this->site();

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator());

        $this->assertPinnedBeforeDeploy(self::OLDER);
    }

    public function test_redeploy_moves_a_pin_without_an_image_to_the_newest_build(): void
    {
        $this->appSha = self::UNBUILT;
        $site = $this->site();

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator(), force: false);

        $this->assertPinnedBeforeDeploy(self::LATEST);
    }

    public function test_follow_head_and_update_head_deploy_the_newest_build_and_never_turn_auto_deploy_on(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())->post(route('ops.sites.follow-head', $site))->assertSessionHas('status');
        $this->assertPinnedBeforeDeploy(self::LATEST);

        $this->actingAs($this->operator())->post(route('ops.sites.update-head', $site))->assertSessionHas('status');

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && (($r->data()['git_commit_sha'] ?? null) === 'HEAD' || ($r->data()['is_auto_deploy_enabled'] ?? null) === true));

        foreach (['site.git_follow_head', 'site.git_update_head'] as $action) {
            $audit = AuditLog::query()->where('subject_id', $site->id)->where('action', $action)->first();
            $this->assertSame(self::LATEST, $audit?->after['git_commit_sha'] ?? null, $action);
            $this->assertFalse($audit?->after['is_auto_deploy'] ?? null);
        }
        $this->assertFalse($site->fresh()->coolify_auto_deploy);
    }

    public function test_pin_accepts_a_built_short_sha_and_writes_the_full_one(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.pin', $site), ['ref' => substr(self::OLDER, 0, 7)])
            ->assertSessionHas('status');

        $this->assertPinnedBeforeDeploy(self::OLDER);
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
    }

    public function test_pin_to_a_commit_without_an_image_is_refused_before_anything_changes(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.pin', $site), ['ref' => self::UNBUILT])
            ->assertSessionHas('error', __('site_ops.image.not_built', ['sha' => substr(self::UNBUILT, 0, 8), 'channel' => 'beta']));

        $this->assertNothingChangedOnTheSiteApp();
    }

    public function test_no_finished_build_refuses_every_deploy(): void
    {
        $this->betaBuilds = [['id' => 12, 'commit' => 'HEAD', 'status' => 'in_progress']];
        $site = $this->site();

        foreach (['ops.sites.deploy', 'ops.sites.follow-head', 'ops.sites.update-head'] as $route) {
            $this->actingAs($this->operator())
                ->post(route($route, $site))
                ->assertSessionHas('error', __('site_ops.image.no_build', ['channel' => 'beta']));
        }

        $this->assertNothingChangedOnTheSiteApp();
    }

    public function test_auto_deploy_cannot_be_turned_on_and_turning_it_off_fills_the_source_commit(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.auto-deploy', $site), ['enabled' => '1'])
            ->assertSessionHas('error', __('site_ops.image.auto_deploy_blocked', ['name' => $site->name]));
        $this->assertNothingChangedOnTheSiteApp();

        $result = app(CoolifyDeploySettings::class)->setAutoDeployMany([$site], true);
        $this->assertSame(1, $result['failed']);
        $this->assertNothingChangedOnTheSiteApp();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.auto-deploy', $site), ['enabled' => '0'])
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true
            && ! array_key_exists('git_commit_sha', $r->data()));
    }

    public function test_deploy_gate_coolify_is_refused_and_ci_keeps_the_pin(): void
    {
        $this->appSha = self::OLDER;
        $site = $this->site();
        $settings = app(CoolifyDeploySettings::class);

        try {
            $settings->setDeployGate($site, DeployGate::Coolify);
            $this->fail('Auto-deploy must stay off for a site that runs built images.');
        } catch (BuiltImageException) {
            $this->assertNothingChangedOnTheSiteApp();
        }

        $settings->setDeployGate($site, DeployGate::Ci);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true
            && ! array_key_exists('git_commit_sha', $r->data()));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
        $this->assertSame(DeployGate::Ci, $site->fresh()->deploy_gate);
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
    }

    public function test_ci_rollout_pins_the_green_commit_and_waits_while_it_is_not_built(): void
    {
        $site = $this->site();
        $settings = app(CoolifyDeploySettings::class);

        $settings->deployForRollout($site, $this->rollout(self::OLDER));
        $this->assertPinnedBeforeDeploy(self::OLDER);

        try {
            $settings->deployForRollout($site, $this->rollout(self::UNBUILT));
            $this->fail('A commit the builder has not finished has no image yet.');
        } catch (BuiltImageException $exception) {
            $this->assertTrue($exception->pending);
        }
    }

    public function test_channel_switch_pins_the_target_channels_newest_build(): void
    {
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Deploying, 'desired_channel' => Channel::Alpha])->save();

        app(ChannelSwitcher::class)->switchOnCoolify($site);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && ($r->data()['git_branch'] ?? null) === 'alpha'
            && ($r->data()['git_commit_sha'] ?? null) === self::ALPHA_LATEST
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
    }

    public function test_activating_a_stopped_site_pins_a_built_image_before_coolify_starts_it(): void
    {
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Stopped])->save();

        app(SiteLifecycle::class)->activate($site, $this->operator(), null);

        $pin = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['git_commit_sha'] ?? null) === self::LATEST
            && ($r->data()['include_source_commit_in_build'] ?? null) === true);
        $start = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/applications/'.self::APP.'/start'));
        $this->assertNotNull($pin);
        $this->assertNotNull($start);
        $this->assertLessThan($start, $pin);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    private function assertPinnedBeforeDeploy(string $sha): void
    {
        $pin = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && ($r->data()['git_commit_sha'] ?? null) === $sha
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true);
        $deploy = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/deploy?')
            && str_contains($r->url(), 'uuid='.self::APP), after: $pin ?? -1);

        $this->assertNotNull($pin, 'PATCH pinning '.$sha);
        $this->assertNotNull($deploy, 'deploy after the pin');
    }

    private function assertNothingChangedOnTheSiteApp(): void
    {
        Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['PATCH', 'POST'], true)
            && str_contains($r->url(), '/applications/'.self::APP));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
    }

    /**
     * @param  callable(Request): bool  $match
     */
    private function firstIndex(callable $match, int $after = -1): ?int
    {
        foreach (Http::recorded() as $index => [$request]) {
            if ($index > $after && $match($request)) {
                return $index;
            }
        }

        return null;
    }

    private function fakeCoolify(): void
    {
        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }

            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-beta')) {
                return Http::response(['count' => count($this->betaBuilds), 'deployments' => $this->betaBuilds], 200);
            }
            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-alpha')) {
                return Http::response([
                    ['id' => 3, 'commit' => self::ALPHA_LATEST, 'status' => 'finished', 'finished_at' => $this->ago(10)],
                ], 200);
            }
            if ($method === 'PATCH' && str_ends_with($url, '/applications/'.self::APP)) {
                $body = $request->data();
                if (array_key_exists('git_commit_sha', $body)) {
                    $this->appSha = (string) $body['git_commit_sha'];
                }

                return Http::response($this->app(autoDeploy: (bool) ($body['is_auto_deploy_enabled'] ?? false)), 200);
            }
            if ($method === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->app(), 200);
            }
            if ($method === 'POST' && (str_contains($url, '/deploy?') || str_ends_with($url, '/start'))) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-'.count(Http::recorded())]]], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function app(bool $autoDeploy = false): array
    {
        return [
            'uuid' => self::APP,
            'build_pack' => 'dockercompose',
            'git_branch' => 'beta',
            'git_commit_sha' => $this->appSha,
            'is_auto_deploy_enabled' => $autoDeploy,
            'settings' => ['is_auto_deploy_enabled' => $autoDeploy],
            'docker_compose_domains' => [['name' => 'app', 'domain' => 'https://built.example.test']],
        ];
    }

    private function rollout(string $sha): FleetRollout
    {
        return FleetRollout::query()->create([
            'channel' => Channel::Beta,
            'sha' => $sha,
            'status' => FleetRolloutStatus::Canary,
            'started_at' => now(),
            'stage_started_at' => now(),
            'summary' => [],
        ]);
    }

    private function ago(int $minutes): string
    {
        return now()->utc()->subMinutes($minutes)->toIso8601ZuluString();
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'built-image-token',
        ]);

        return Site::factory()->create([
            'name' => 'Built Shop',
            'channel' => Channel::Beta,
            'status' => SiteStatus::Active,
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
