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

    /** `dockercompose` (git pin) or `dockerimage` (image tag pin, rolling deploy). */
    private string $buildPack = 'dockercompose';

    /** Image tag of a Docker Image app. */
    private string $imageTag = 'latest';

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

    public function test_both_deploy_gates_keep_auto_deploy_off_and_the_pin(): void
    {
        $this->appSha = self::OLDER;
        $site = $this->site();
        $settings = app(CoolifyDeploySettings::class);

        $settings->setDeployGate($site, DeployGate::Ci);
        $this->assertSame(DeployGate::Ci, $site->fresh()->deploy_gate);

        // Leaving the CI gate is a manual release: auto-deploy is never turned on.
        $settings->setDeployGate($site, DeployGate::Coolify);
        $this->assertSame(DeployGate::Coolify, $site->fresh()->deploy_gate);

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['is_auto_deploy_enabled'] ?? false) === true);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true
            && ! array_key_exists('git_commit_sha', $r->data()));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_auto_deploy);
    }

    public function test_the_site_panel_shows_the_release_card_instead_of_auto_deploy_and_head(): void
    {
        $this->appSha = self::OLDER;
        $site = $this->site();
        $site->forceFill(['coolify_pinned_sha' => self::OLDER, 'coolify_pin_held' => true, 'deploy_gate' => DeployGate::Ci])->save();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.coolify-ops.panel', $site))
            ->assertOk()
            ->assertSee('data-built-image', false)
            ->assertSee(__('site_ops.release.title'))
            ->assertSee(__('site_ops.release.status_behind', ['sha' => substr(self::OLDER, 0, 7), 'latest' => substr(self::LATEST, 0, 7), 'count' => 1]))
            ->assertSee(__('site_ops.release.held'))
            ->assertSee(__('site_ops.release.deploy_latest'))
            ->assertSee('value="'.self::LATEST.'"', false)
            ->assertSee('value="'.self::OLDER.'"', false)
            ->assertDontSee('value="'.self::UNBUILT.'"', false)
            ->assertDontSee(__('site_ops.auto_deploy.on_button'))
            ->assertDontSee(__('site_ops.pin.follow_button'))
            ->assertDontSee(route('ops.sites.follow-head', $site), false)
            // Deploy gate card: CI gate, held release, the way back keeps auto-deploy off.
            ->assertSee(__('rollouts.site.held_note'))
            ->assertSee(__('rollouts.site.switch_to_manual'))
            ->assertDontSee(__('rollouts.site.switch_to_coolify'));

        $site->forceFill(['deploy_gate' => DeployGate::Coolify])->save();
        $this->actingAs($this->operator())
            ->get(route('ops.sites.coolify-ops.panel', $site))
            ->assertOk()
            ->assertSee('">'.__('rollouts.gate.manual').'</span>', false)
            ->assertDontSee('">'.__('rollouts.gate.coolify').'</span>', false);
    }

    public function test_the_header_and_list_menus_offer_the_newest_image_instead_of_head(): void
    {
        $site = $this->site();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('site_ops.release.deploy_latest'))
            ->assertDontSee(route('ops.sites.follow-head', $site), false);

        $this->actingAs($operator)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('site_ops.release.deploy_latest'))
            ->assertSee(__('site_ops.release.bulk_auto_note'))
            ->assertSee(__('rollouts.bulk.to_manual'))
            ->assertDontSee(route('ops.sites.bulk.follow-head'), false)
            ->assertDontSee(__('site_ops.bulk.auto_on'))
            ->assertDontSee(__('rollouts.bulk.to_coolify'));
    }

    public function test_flashes_describe_the_newest_image(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.update-head', $site))
            ->assertSessionHas('status', __('site_ops.release.latest_done', ['name' => $site->name]));

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy-gate', $site), ['gate' => 'ci'])
            ->assertSessionHas('status', __('rollouts.site.gate_done', ['name' => $site->name, 'gate' => __('rollouts.gate.ci')]));
        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy-gate', $site), ['gate' => 'coolify'])
            ->assertSessionHas('status', __('rollouts.site.gate_done', ['name' => $site->name, 'gate' => __('rollouts.gate.manual')]));
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

    public function test_docker_image_redeploy_keeps_a_built_tag_and_patches_only_the_tag(): void
    {
        $this->buildPack = 'dockerimage';
        $this->imageTag = self::OLDER;
        $site = $this->site();

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator());

        $this->assertImagePinnedBeforeDeploy(self::OLDER);
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_auto_deploy);
    }

    public function test_docker_image_redeploy_moves_a_tag_without_a_build_to_the_newest_build(): void
    {
        $this->buildPack = 'dockerimage';
        $this->imageTag = self::UNBUILT;
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $site))
            ->assertSessionHas('status');

        $this->assertImagePinnedBeforeDeploy(self::LATEST);
        $this->assertSame(self::LATEST, $this->imageTag);
    }

    public function test_docker_image_pin_and_update_head_set_the_image_tag(): void
    {
        $this->buildPack = 'dockerimage';
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.pin', $site), ['ref' => substr(self::OLDER, 0, 7)])
            ->assertSessionHas('status');
        $this->assertImagePinnedBeforeDeploy(self::OLDER);
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);
        $this->assertTrue($site->fresh()->coolify_pin_held);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.update-head', $site))
            ->assertSessionHas('status', __('site_ops.release.latest_done', ['name' => $site->name]));
        $this->assertImagePinnedBeforeDeploy(self::LATEST);
        $this->assertSame(self::LATEST, $site->fresh()->coolify_pinned_sha);
        $this->assertFalse($site->fresh()->coolify_pin_held);
    }

    public function test_docker_image_pin_to_a_commit_without_a_build_changes_nothing(): void
    {
        $this->buildPack = 'dockerimage';
        $this->imageTag = self::OLDER;
        $site = $this->site();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.pin', $site), ['ref' => self::UNBUILT])
            ->assertSessionHas('error', __('site_ops.image.not_built', ['sha' => substr(self::UNBUILT, 0, 8), 'channel' => 'beta']));

        $this->assertNothingChangedOnTheSiteApp();
        $this->assertSame(self::OLDER, $this->imageTag);
    }

    public function test_docker_image_ci_rollout_tags_the_green_commit(): void
    {
        $this->buildPack = 'dockerimage';
        $site = $this->site();

        app(CoolifyDeploySettings::class)->deployForRollout($site, $this->rollout(self::OLDER));

        $this->assertImagePinnedBeforeDeploy(self::OLDER);
    }

    public function test_docker_image_channel_switch_tags_the_target_channels_newest_build_without_a_branch(): void
    {
        $this->buildPack = 'dockerimage';
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Deploying, 'desired_channel' => Channel::Alpha])->save();

        app(ChannelSwitcher::class)->switchOnCoolify($site);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && ($r->data()['docker_registry_image_tag'] ?? null) === self::ALPHA_LATEST
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false);
        $this->assertNoGitPinOnTheImageApp();
        $this->assertSame(self::ALPHA_LATEST, $site->fresh()->coolify_pinned_sha);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
    }

    public function test_docker_image_activate_tags_a_built_image_before_coolify_starts_it(): void
    {
        $this->buildPack = 'dockerimage';
        $this->imageTag = self::OLDER;
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Stopped])->save();

        app(SiteLifecycle::class)->activate($site, $this->operator(), null);

        $pin = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['docker_registry_image_tag'] ?? null) === self::OLDER);
        $start = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/applications/'.self::APP.'/start'));
        $this->assertNotNull($pin);
        $this->assertNotNull($start);
        $this->assertLessThan($start, $pin);
        $this->assertNoGitPinOnTheImageApp();
    }

    public function test_docker_image_snapshot_reads_the_pinned_commit_from_the_image_tag(): void
    {
        $this->buildPack = 'dockerimage';
        $this->imageTag = self::OLDER;
        $site = $this->site();

        $snapshot = app(CoolifyDeploySettings::class)->snapshot($site);

        $this->assertSame('dockerimage', $snapshot['build_pack']);
        $this->assertSame(self::OLDER, $snapshot['git_commit_sha']);
        $this->assertSame(self::OLDER, $site->fresh()->coolify_pinned_sha);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.coolify-ops.panel', $site))
            ->assertOk()
            ->assertSee(__('site_ops.release.status_behind', ['sha' => substr(self::OLDER, 0, 7), 'latest' => substr(self::LATEST, 0, 7), 'count' => 1]));
    }

    private function assertPinnedBeforeDeploy(string $sha): void
    {
        $pin = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && ($r->data()['git_commit_sha'] ?? null) === $sha
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true
            && ! array_key_exists('docker_registry_image_tag', $r->data()));
        $deploy = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/deploy?')
            && str_contains($r->url(), 'uuid='.self::APP), after: $pin ?? -1);

        $this->assertNotNull($pin, 'PATCH pinning '.$sha);
        $this->assertNotNull($deploy, 'deploy after the pin');
    }

    /**
     * A Docker Image app is pinned by its image tag only: no git ref, the image
     * name untouched, auto-deploy off; then the deploy.
     */
    private function assertImagePinnedBeforeDeploy(string $sha): void
    {
        $pin = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && ($r->data()['docker_registry_image_tag'] ?? null) === $sha
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false);
        $deploy = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/deploy?')
            && str_contains($r->url(), 'uuid='.self::APP), after: $pin ?? -1);

        $this->assertNotNull($pin, 'PATCH tagging '.$sha);
        $this->assertNotNull($deploy, 'deploy after the tag pin');
        $this->assertNoGitPinOnTheImageApp();
    }

    private function assertNoGitPinOnTheImageApp(): void
    {
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && (array_key_exists('git_commit_sha', $r->data())
                || array_key_exists('git_branch', $r->data())
                || array_key_exists('docker_registry_image_name', $r->data())));
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
                if (array_key_exists('docker_registry_image_tag', $body)) {
                    $this->imageTag = (string) $body['docker_registry_image_tag'];
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
        if ($this->buildPack === 'dockerimage') {
            // Coolify keeps placeholder git fields on a Docker Image app.
            return [
                'uuid' => self::APP,
                'build_pack' => 'dockerimage',
                'git_repository' => 'coollabsio/coolify',
                'git_branch' => 'main',
                'git_commit_sha' => 'HEAD',
                'docker_registry_image_name' => '127.0.0.1:5000/deamon-cms',
                'docker_registry_image_tag' => $this->imageTag,
                'is_auto_deploy_enabled' => $autoDeploy,
                'settings' => ['is_auto_deploy_enabled' => $autoDeploy],
                'fqdn' => 'https://built.example.test',
            ];
        }

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
