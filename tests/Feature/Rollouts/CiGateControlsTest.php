<?php

namespace Tests\Feature\Rollouts;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\FleetRolloutStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Models\CoolifyConnection;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Models\Theme;
use App\Models\User;
use App\Services\GitHub\CiBranchHeads;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Operator controls of the CI gate: per-site gate + canary, bulk gate, the
 * rollouts list / detail with halt and resume, and the theme `ci_gate` switch.
 */
class CiGateControlsTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'gate-app-1';

    private CoolifyConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake([AdvanceFleetRolloutJob::class]);
        config(['ops.deamon.repository' => 'https://github.com/codron-co/deamon.git']);

        $this->connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'gate-token',
        ]);
    }

    public function test_switching_a_site_to_the_ci_gate_turns_auto_deploy_off_and_follows_head_without_deploying(): void
    {
        $site = $this->site(['coolify_pinned_sha' => 'abc1234', 'coolify_auto_deploy' => true]);
        $this->fakeCoolify(autoDeploy: false, sha: 'HEAD');

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.deploy-gate', $site), ['gate' => 'ci'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/applications/'.self::APP)
                && ($body['is_auto_deploy_enabled'] ?? null) === false
                && ($body['git_commit_sha'] ?? null) === 'HEAD';
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/deploy'));

        $fresh = $site->fresh();
        $this->assertSame(DeployGate::Ci, $fresh->deploy_gate);
        $this->assertFalse($fresh->coolify_auto_deploy);
        $this->assertNull($fresh->coolify_pinned_sha);
        $audit = $site->auditLogs()->where('action', 'site.deploy_gate_updated')->sole();
        $this->assertSame('coolify', $audit->before['deploy_gate']);
        $this->assertSame('ci', $audit->after['deploy_gate']);
    }

    public function test_switching_back_to_coolify_turns_auto_deploy_on(): void
    {
        $site = $this->site(['deploy_gate' => DeployGate::Ci, 'coolify_auto_deploy' => false]);
        $this->fakeCoolify(autoDeploy: true, sha: 'HEAD');

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.deploy-gate', $site), ['gate' => 'coolify'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && ($request->data()['is_auto_deploy_enabled'] ?? null) === true);
        $this->assertSame(DeployGate::Coolify, $site->fresh()->deploy_gate);
        $this->assertTrue($site->fresh()->coolify_auto_deploy);
    }

    public function test_a_ci_gated_site_refuses_auto_deploy_on_and_follow_head_keeps_it_off(): void
    {
        $site = $this->site(['deploy_gate' => DeployGate::Ci]);
        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'PATCH') {
                return Http::response($this->appPayload(false, 'HEAD'), 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-1']]], 200);
            }

            return Http::response([], 404);
        });
        Queue::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.auto-deploy', $site), ['enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error');
        Http::assertNothingSent();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.follow-head', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && ($request->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($request->data()['git_commit_sha'] ?? null) === 'HEAD');
        $this->assertFalse($site->fresh()->coolify_auto_deploy);
    }

    public function test_canary_flag_is_saved_and_audited_without_calling_coolify(): void
    {
        $site = $this->site(['deploy_gate' => DeployGate::Ci]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.deploy-canary', $site), ['canary' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($site->fresh()->deploy_canary);
        $this->assertTrue($site->auditLogs()->where('action', 'site.deploy_canary_updated')->exists());
        Http::assertNothingSent();
    }

    public function test_viewer_cannot_change_gate_or_canary(): void
    {
        $site = $this->site();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.deploy-gate', $site), ['gate' => 'ci'])
            ->assertForbidden();
        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.deploy-canary', $site), ['canary' => '1'])
            ->assertForbidden();

        $this->assertSame(DeployGate::Coolify, $site->fresh()->deploy_gate ?? DeployGate::Coolify);
    }

    public function test_bulk_deploy_gate_switches_the_selected_sites(): void
    {
        $first = $this->site();
        $second = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'gate-app-2',
            'coolify_connection_id' => $this->connection->id,
        ]);
        $untouched = $this->site(['coolify_app_uuid' => 'gate-app-3']);
        Http::fake(function (Request $request) {
            return Http::response($this->appPayload(false, 'HEAD'), 200);
        });

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.bulk.deploy-gate'), [
                'site_ids' => [$first->id, $second->id],
                'gate' => 'ci',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(DeployGate::Ci, $first->fresh()->deploy_gate);
        $this->assertSame(DeployGate::Ci, $second->fresh()->deploy_gate);
        $this->assertNotSame(DeployGate::Ci, $untouched->fresh()->deploy_gate);
        Http::assertSentCount(2);
    }

    public function test_bulk_deploy_gate_over_fetch_queues_a_background_job(): void
    {
        $site = $this->site();

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->postJson(route('ops.sites.bulk.deploy-gate'), [
                'site_ids' => [$site->id],
                'gate' => 'ci',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.type', 'sites.bulk_deploy_gate');
    }

    public function test_site_detail_panel_shows_gate_and_canary_controls(): void
    {
        $site = $this->site(['deploy_gate' => DeployGate::Ci, 'deploy_canary' => true]);
        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload(false, 'HEAD'), 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.coolify-ops.panel', $site))
            ->assertOk()
            ->assertSee(route('ops.sites.deploy-gate', $site), false)
            ->assertSee(route('ops.sites.deploy-canary', $site), false)
            ->assertSee(__('rollouts.site.title'))
            ->assertSee(__('rollouts.gate.ci'))
            ->assertDontSee(__('site_ops.auto_deploy.on_button'));
    }

    public function test_rollouts_list_and_detail_render_for_every_role(): void
    {
        $rollout = $this->rollout(FleetRolloutStatus::Halted, [
            'halted_reason' => 'Canary failed (build or health): Shop',
            'summary' => [
                'canary' => ['site_ids' => ['a'], 'deployments' => ['a' => 1], 'healthy' => [], 'failed' => ['a'], 'pending' => []],
                'halt' => ['code' => 'canary_failed', 'sites' => ['Shop']],
            ],
        ]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.rollouts'))
            ->assertOk()
            ->assertSee($rollout->shortSha())
            ->assertSee(__('rollouts.status.halted'))
            ->assertSee(route('ops.rollouts.show', $rollout), false)
            ->assertDontSee(route('ops.rollouts.resume', $rollout), false);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.rollouts.show', $rollout))
            ->assertOk()
            ->assertSee('Shop')
            ->assertDontSee(route('ops.rollouts.resume', $rollout), false);
    }

    public function test_empty_rollouts_list_explains_itself(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.rollouts'))
            ->assertOk()
            ->assertSee(__('rollouts.empty.title'));
    }

    public function test_operator_can_halt_an_open_rollout(): void
    {
        $rollout = $this->rollout(FleetRolloutStatus::Canary);
        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->post(route('ops.rollouts.halt', $rollout))
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $rollout->fresh();
        $this->assertSame(FleetRolloutStatus::Halted, $fresh->status);
        $this->assertSame($operator->id, $fresh->halted_by);
        $this->assertSame('manual', $fresh->summary['halt']['code']);
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.halted')->where('actor_user_id', $operator->id)->exists());
    }

    public function test_only_super_admin_can_resume_a_halted_rollout(): void
    {
        app(CiBranchHeads::class)->recordPush('codron-co/deamon', 'main', 'sha1234567');
        $rollout = $this->rollout(FleetRolloutStatus::Halted);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.rollouts.resume', $rollout))
            ->assertForbidden();
        $this->assertSame(FleetRolloutStatus::Halted, $rollout->fresh()->status);

        $admin = $this->user(OpsRole::SuperAdmin);
        $this->actingAs($admin)
            ->post(route('ops.rollouts.resume', $rollout))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(FleetRolloutStatus::Fanout, $rollout->fresh()->status);
        $this->assertNull($rollout->fresh()->halted_reason);
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.resumed')->where('actor_user_id', $admin->id)->exists());
        Queue::assertPushed(AdvanceFleetRolloutJob::class);
    }

    public function test_resume_is_refused_once_the_branch_moved_on(): void
    {
        app(CiBranchHeads::class)->recordPush('codron-co/deamon', 'main', 'newer999');
        $rollout = $this->rollout(FleetRolloutStatus::Halted);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.rollouts.resume', $rollout))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(FleetRolloutStatus::Halted, $rollout->fresh()->status);
        Queue::assertNotPushed(AdvanceFleetRolloutJob::class);
    }

    public function test_theme_ci_gate_checkbox_is_saved_and_audited(): void
    {
        $theme = Theme::factory()->create(['default_ref' => 'main']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes.show', $theme))
            ->assertOk()
            ->assertSee('name="ci_gate"', false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->put(route('ops.themes.update', $theme), [
                'visibility' => $theme->visibility->value,
                'default_ref' => 'main',
                'ci_gate' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($theme->fresh()->ci_gate);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_gate_updated')->exists());

        // The visibility form does not post the field and must not reset it.
        $this->actingAs($this->user(OpsRole::Operator))
            ->put(route('ops.themes.update', $theme), [
                'visibility' => $theme->visibility->value,
                'default_ref' => 'main',
            ])
            ->assertRedirect();
        $this->assertTrue($theme->fresh()->ci_gate);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rollout(FleetRolloutStatus $status, array $attributes = []): FleetRollout
    {
        return FleetRollout::query()->create(array_merge([
            'channel' => Channel::Main,
            'sha' => 'sha1234567',
            'status' => $status,
            'started_at' => now()->subMinutes(5),
            'summary' => [],
        ], $attributes));
    }

    private function fakeCoolify(bool $autoDeploy, ?string $sha): void
    {
        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload($autoDeploy, $sha), 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(bool $autoDeploy, ?string $sha): array
    {
        return [
            'uuid' => self::APP,
            'build_pack' => 'dockercompose',
            'is_auto_deploy_enabled' => $autoDeploy,
            'settings' => ['is_auto_deploy_enabled' => $autoDeploy],
            'git_commit_sha' => $sha,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Site
    {
        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $this->connection->id,
        ], $attributes));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
