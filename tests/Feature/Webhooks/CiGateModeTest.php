<?php

namespace Tests\Feature\Webhooks;

use App\Enums\Channel;
use App\Enums\CiGateMode;
use App\Enums\DeployGate;
use App\Enums\FleetRolloutStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Jobs\SyncCoolifyEnvCatalogJob;
use App\Models\AuditLog;
use App\Models\CiBranchHead;
use App\Models\CiGateSetting;
use App\Models\CoolifyConnection;
use App\Models\FleetRollout;
use App\Models\GithubSetting;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Support\GitHubWebhookSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Settings → CI gate: what CI-gated sites and themes do when GitHub CI is off.
 */
class CiGateModeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'plane-github-webhook-secret';

    private const CMS = 'codron-co/deamon';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake([AdvanceFleetRolloutJob::class, SyncCoolifyEnvCatalogJob::class]);
        config(['ops.deamon.repository' => 'https://github.com/codron-co/deamon.git']);

        GithubSetting::factory()->create([
            'org' => 'deamon-themes',
            'webhook_secret' => self::SECRET,
        ]);
    }

    public function test_no_row_means_wait_for_green_ci(): void
    {
        $this->assertSame(CiGateMode::Enforce, CiGateSetting::mode());

        $this->ciSite();
        $this->push('main', 'push111');

        $this->assertSame(0, FleetRollout::query()->count());
    }

    public function test_bypass_starts_the_rollout_on_push_and_the_later_green_run_starts_nothing(): void
    {
        $this->setMode(CiGateMode::Bypass);
        $this->ciSite();

        $this->push('main', 'push222');

        $rollout = FleetRollout::query()->sole();
        $this->assertSame(Channel::Main, $rollout->channel);
        $this->assertSame('push222', $rollout->sha);
        $this->assertSame(FleetRolloutStatus::Canary, $rollout->status);
        $this->assertSame('push_without_ci', $rollout->auditLogs()->where('action', 'fleet_rollout.started')->sole()->after['trigger']);
        Queue::assertPushed(AdvanceFleetRolloutJob::class);

        $this->workflowRun('main', 'push222', 'success')->assertOk()->assertJson(['updated' => false, 'ci' => 'promote']);
        $this->assertSame(1, FleetRollout::query()->count());
    }

    public function test_bypass_newer_push_supersedes_the_open_rollout(): void
    {
        $this->setMode(CiGateMode::Bypass);
        $this->ciSite();

        $this->push('main', 'old111');
        $this->push('main', 'new222');

        $old = FleetRollout::query()->where('sha', 'old111')->sole();
        $this->assertSame(FleetRolloutStatus::Superseded, $old->status);
        $this->assertSame('newer_push', $old->summary['superseded']['code']);
        $this->assertSame(FleetRolloutStatus::Canary, FleetRollout::query()->where('sha', 'new222')->sole()->status);
    }

    public function test_bypass_leaves_coolify_gated_sites_alone(): void
    {
        $this->setMode(CiGateMode::Bypass);
        $this->ciSite(['deploy_gate' => DeployGate::Coolify]);

        $this->push('main', 'push333');

        $this->assertSame(0, FleetRollout::query()->count());
    }

    public function test_pause_holds_a_green_run_and_audits_it(): void
    {
        $this->setMode(CiGateMode::Pause);
        $this->ciSite();
        $this->push('main', 'green444');

        $this->workflowRun('main', 'green444', 'success')
            ->assertOk()
            ->assertJson(['updated' => false, 'ci' => 'promote']);

        $this->assertSame(0, FleetRollout::query()->count());
        Queue::assertNotPushed(AdvanceFleetRolloutJob::class);
        $this->assertSame('green444', AuditLog::query()->where('action', 'ci.run_paused')->sole()->after['sha']);
        $this->assertSame('success', CiBranchHead::for(self::CMS, 'main')->ci_status);
    }

    public function test_bypass_fans_out_a_ci_gated_theme_on_push(): void
    {
        $this->setMode(CiGateMode::Bypass);
        [$theme, $install] = $this->gatedTheme();
        Http::fake([
            'https://gated.example.test/internal/control/v1/themes/update' => Http::response(['ok' => true, 'sha' => 'theme222'], 200),
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme222',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson(['updated' => true, 'fanout' => 1]);

        $this->assertSame('theme222', $theme->fresh()->latest_sha);
        $this->assertSame('theme222', $install->fresh()->pinned_sha);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_bypassed')->exists());

        $this->workflowRun('main', 'theme222', 'success', repo: $theme->repo_full_name)
            ->assertOk()->assertJson(['updated' => false, 'fanout' => 0]);
        Http::assertSentCount(1);
    }

    public function test_pause_keeps_a_ci_gated_theme_on_push_and_on_green(): void
    {
        $this->setMode(CiGateMode::Pause);
        [$theme] = $this->gatedTheme();

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme222',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson(['updated' => false, 'fanout' => 0]);

        $this->workflowRun('main', 'theme222', 'success', repo: $theme->repo_full_name)
            ->assertOk()->assertJson(['updated' => false, 'fanout' => 0]);

        $this->assertSame('theme111', $theme->fresh()->latest_sha);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_paused')->exists());
        Http::assertNothingSent();
    }

    public function test_super_admin_sets_the_mode_and_it_is_audited(): void
    {
        $admin = $this->userWith(OpsRole::SuperAdmin);

        $this->actingAs($admin)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.ci_gate.title'), false)
            ->assertSee(route('ops.settings.ci_gate'), false);

        $this->actingAs($admin)
            ->post(route('ops.settings.ci_gate'), ['mode' => 'pause'])
            ->assertRedirect(route('ops.settings').'#ci-gate-heading');

        $this->assertSame(CiGateMode::Pause, CiGateSetting::mode());
        $audit = AuditLog::query()->where('action', 'ci.gate_mode_updated')->sole();
        $this->assertSame(['mode' => 'enforce'], $audit->before);
        $this->assertSame(['mode' => 'pause'], $audit->after);
        $this->assertSame($admin->id, $audit->actor_user_id);

        $this->actingAs($admin)
            ->get(route('ops.rollouts'))
            ->assertOk()
            ->assertSee(__('settings.ci_gate.rollouts_banner', ['mode' => CiGateMode::Pause->label()]), false);

        $this->actingAs($admin)->post(route('ops.settings.ci_gate'), ['mode' => 'nope'])->assertSessionHasErrors('mode');
        $this->assertSame(1, CiGateSetting::query()->count());
    }

    public function test_operator_cannot_change_the_mode(): void
    {
        $operator = $this->userWith(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.ci_gate.title'), false)
            ->assertDontSee(route('ops.settings.ci_gate'), false);

        $this->actingAs($operator)
            ->post(route('ops.settings.ci_gate'), ['mode' => 'bypass'])
            ->assertForbidden();

        $this->assertSame(CiGateMode::Enforce, CiGateSetting::mode());
    }

    private function setMode(CiGateMode $mode): void
    {
        CiGateSetting::query()->create(['mode' => $mode]);
    }

    private function userWith(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function push(string $branch, string $sha): void
    {
        $this->signedPost('push', [
            'ref' => 'refs/heads/'.$branch,
            'after' => $sha,
            'repository' => ['full_name' => self::CMS],
        ])->assertOk();
    }

    private function workflowRun(
        string $branch,
        string $sha,
        string $conclusion,
        string $name = 'CI',
        string $runEvent = 'push',
        string $action = 'completed',
        string $repo = self::CMS,
        ?string $delivery = null,
    ): TestResponse {
        return $this->signedPost('workflow_run', [
            'action' => $action,
            'workflow_run' => [
                'name' => $name,
                'event' => $runEvent,
                'status' => 'completed',
                'conclusion' => $conclusion,
                'head_branch' => $branch,
                'head_sha' => $sha,
            ],
            'repository' => ['full_name' => $repo],
        ], delivery: $delivery);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ciSite(array $attributes = []): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'token',
        ]);

        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'app-'.fake()->unique()->lexify('??????'),
            'coolify_connection_id' => $connection->id,
            'deploy_gate' => DeployGate::Ci,
        ], $attributes));
    }

    /**
     * @return array{0: Theme, 1: SiteThemeInstallation}
     */
    private function gatedTheme(bool $ciGate = true): array
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'repo_full_name' => 'deamon-themes/deamon-theme-gated',
            'theme_id' => 'gated',
            'default_ref' => 'main',
            'latest_sha' => 'theme111',
            'ci_gate' => $ciGate,
        ]);
        $site = Site::factory()->withSecrets()->create([
            'primary_domain' => 'gated.example.test',
            'agent_base_url' => 'https://gated.example.test',
            'last_health_payload' => ['deamon_version' => '1.2.32'],
        ]);
        $install = SiteThemeInstallation::factory()->active()->autoUpdate()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'pinned_sha' => 'theme111',
        ]);

        return [$theme, $install];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(string $event, array $payload, ?string $delivery = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => GitHubWebhookSignature::sign(self::SECRET, $body),
            'HTTP_X_GITHUB_EVENT' => $event,
        ];
        if ($delivery !== null) {
            $server['HTTP_X_GITHUB_DELIVERY'] = $delivery;
        }

        return $this->call('POST', '/webhooks/github', [], [], [], $server, $body);
    }
}
