<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\SwitchSiteChannelJob;
use App\Models\AuditLog;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'default_project_uuid' => 'proj_test',
            'default_server_uuid' => 'srv_test',
        ]);
    }

    public function test_operator_switches_main_to_beta_with_confirm(): void
    {
        $this->fakeCoolifyChannelSwitch('beta');

        $operator = $this->user(OpsRole::Operator);
        $site = $this->activeSite();

        $this->actingAs($operator)
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();

        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertNull($site->desired_channel);
        $this->assertSame(SiteStatus::Active, $site->status);

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'channel' => Channel::Beta->value,
            'trigger' => DeploymentTrigger::ChannelSwitch->value,
            'coolify_deployment_uuid' => 'dep-switch-1',
            'status' => DeploymentStatus::Finished->value,
            'commit_sha' => 'switchsha',
            'requested_by' => $operator->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.channel_switch_started',
            'subject_id' => $site->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.channel_switched',
            'subject_id' => $site->id,
        ]);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/coolify-app-1'
                && ($data['git_branch'] ?? null) === 'beta'
                && array_key_exists('git_commit_sha', $data)
                && $data['git_commit_sha'] === 'HEAD'
                && ! array_key_exists('fqdn', $data);
        });

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://coolify.test/api/v1/deploy')
                && str_contains($request->url(), 'uuid=coolify-app-1');
        });

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                || str_contains($request->url(), '/applications/dockercompose')
                || ($request->method() === 'POST' && str_contains($request->url(), '/applications/'));
        });

        $this->assertSecretsStayPrivate($site);
    }

    public function test_channel_switch_writes_app_env_without_environment_uuid_patch(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'default_project_uuid' => 'proj_test',
        ]);
        CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => 'proj_test',
            'uuid' => 'env-prod-uuid',
            'name' => 'production',
            'is_active' => true,
        ]);
        CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => 'proj_test',
            'uuid' => 'env-beta-uuid',
            'name' => 'beta',
            'is_active' => true,
        ]);

        $site = $this->activeSite([
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => 'proj_test',
            'coolify_environment_uuid' => 'env-prod-uuid',
        ]);

        $this->fakeCoolifyChannelSwitch('beta');

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $this->assertSame('env-prod-uuid', $site->fresh()->coolify_environment_uuid);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/coolify-app-1'
                && ($data['git_branch'] ?? null) === 'beta'
                && array_key_exists('git_commit_sha', $data)
                && $data['git_commit_sha'] === 'HEAD'
                && ! array_key_exists('environment_uuid', $data);
        });
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/envs')) {
                return false;
            }

            $payload = $request->data();
            $rows = $payload['data'] ?? [];
            $map = [];
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['key'])) {
                    $map[$row['key']] = $row['value'] ?? null;
                }
            }

            return ($map['APP_ENV'] ?? null) === 'staging'
                && ($map['DEAMON_CHANNEL'] ?? null) === 'beta';
        });
    }

    public function test_desired_channel_is_set_while_switch_job_is_queued(): void
    {
        Queue::fake();

        $site = $this->activeSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'alpha',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();

        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame(Channel::Alpha, $site->desired_channel);
        $this->assertSame(SiteStatus::Deploying, $site->status);

        Queue::assertPushed(SwitchSiteChannelJob::class, function (SwitchSiteChannelJob $job) use ($site): bool {
            return $job->siteId === (string) $site->id;
        });

        Http::assertNothingSent();
    }

    public function test_main_to_beta_without_confirm_is_rejected(): void
    {
        $site = $this->activeSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'beta',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(Channel::Main, $site->channel);
        $this->assertNull($site->desired_channel);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertDatabaseMissing('deployments', ['site_id' => $site->id]);
        Http::assertNothingSent();
    }

    public function test_beta_to_main_passes_version_gate_when_agent_health_is_missing(): void
    {
        $this->fakeCoolifyChannelSwitch('main');

        $site = $this->activeSite([
            'channel' => Channel::Beta,
            'last_health_payload' => null,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'main',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();

        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertNull($site->desired_channel);
    }

    public function test_beta_to_main_is_blocked_when_reported_version_is_below_minimum(): void
    {
        config(['ops.channel_switch.main_minimum_version' => '2.0.0']);

        $site = $this->activeSite([
            'channel' => Channel::Beta,
            'last_health_payload' => ['deamon_version' => '1.0.0'],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'main',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertSame(SiteStatus::Active, $site->status);
        Http::assertNothingSent();
    }

    public function test_operator_cannot_force_version_gate(): void
    {
        config(['ops.channel_switch.main_minimum_version' => '2.0.0']);

        $site = $this->activeSite([
            'channel' => Channel::Alpha,
            'last_health_payload' => ['deamon_version' => '1.0.0'],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'main',
                'force' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();
        $this->assertSame(Channel::Alpha, $site->channel);
        Http::assertNothingSent();
    }

    public function test_super_admin_can_force_version_gate(): void
    {
        config(['ops.channel_switch.main_minimum_version' => '2.0.0']);
        $this->fakeCoolifyChannelSwitch('main');

        $site = $this->activeSite([
            'channel' => Channel::Beta,
            'last_health_payload' => ['deamon_version' => '1.0.0'],
        ]);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'main',
                'force' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();
        $this->assertSame(Channel::Main, $site->channel);

        $started = AuditLog::query()
            ->where('subject_id', $site->id)
            ->where('action', 'site.channel_switch_started')
            ->first();

        $this->assertNotNull($started);
        $this->assertTrue((bool) ($started->after['forced'] ?? false));
    }

    public function test_deploying_site_cannot_switch_again(): void
    {
        $site = $this->activeSite([
            'status' => SiteStatus::Deploying,
            'desired_channel' => Channel::Beta,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'alpha',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();
        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame(Channel::Beta, $site->desired_channel);
        $this->assertSame(SiteStatus::Deploying, $site->status);
        Http::assertNothingSent();
    }

    public function test_viewer_cannot_switch_channel(): void
    {
        $site = $this->activeSite();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertDontSee('action="'.route('ops.sites.channel', $site).'"', false);

        $site->refresh();
        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame(SiteStatus::Active, $site->status);
        Http::assertNothingSent();
    }

    public function test_channel_outside_allowlist_is_rejected(): void
    {
        $site = $this->activeSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'nightly',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHasErrors('channel');

        $site->refresh();
        $this->assertSame(Channel::Main, $site->channel);
        Http::assertNothingSent();
    }

    public function test_coolify_branch_patch_failure_marks_error_and_keeps_channel(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/applications/coolify-app-1')) {
                return Http::response(['message' => 'git_branch update failed'], 500);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $site = $this->activeSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $site), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame(Channel::Beta, $site->desired_channel);
        $this->assertSame(SiteStatus::Error, $site->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.channel_switch_failed',
            'subject_id' => $site->id,
        ]);

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'DELETE';
        });
    }

    public function test_draft_and_unprovisioned_sites_cannot_switch(): void
    {
        $draft = Site::factory()->create([
            'status' => SiteStatus::Draft,
            'channel' => Channel::Main,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $draft), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $draft))
            ->assertSessionHas('error');

        $activeWithoutApp = $this->activeSite(['coolify_app_uuid' => null]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.channel', $activeWithoutApp), [
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $activeWithoutApp))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_show_keeps_volume_note_in_hint_and_uses_confirm_modal(): void
    {
        $site = $this->activeSite();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('action="'.route('ops.sites.channel', $site).'"', false)
            ->assertSee('data-confirm=', false)
            ->assertSee('Leave main?', false)
            ->assertSee('volumes stay', false)
            ->assertSee('Never DELETE the Coolify application', false)
            ->assertDontSee('window.confirm', false)
            ->getContent();

        $this->assertStringNotContainsString('Force switch to main', $html);
        $this->assertMatchesRegularExpression('/class="site-hint"[^>]*>[\s\S]*Never DELETE the Coolify application/', $html);
        $this->assertStringNotContainsString('<p class="field-hint">', $html);
    }

    public function test_channel_switch_from_beta_still_uses_confirm_modal(): void
    {
        $site = $this->activeSite(['channel' => Channel::Beta]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-confirm=', false)
            ->assertSee(__('sites.channel_switch.confirm_switch_title'), false)
            ->assertDontSee('window.confirm', false);
    }

    public function test_super_admin_sees_force_checkbox_when_leaving_beta(): void
    {
        $site = $this->activeSite(['channel' => Channel::Beta]);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Force switch to main', false)
            ->assertDontSee('Leave main?', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activeSite(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'channel' => Channel::Main,
            'desired_channel' => null,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'coolify-app-1',
            'coolify_server_uuid' => 'srv_test',
        ], $overrides));
    }

    private function fakeCoolifyChannelSwitch(string $branch): void
    {
        Http::fake(function (Request $request) use ($branch) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'DELETE') {
                return Http::response(['error' => 'DELETE is forbidden for channel switch'], 500);
            }

            if ($method === 'PATCH' && str_contains($url, '/envs')) {
                return Http::response([], 200);
            }

            if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
                $this->assertSame($branch, $request->data()['git_branch'] ?? null);

                return Http::response([
                    'uuid' => 'coolify-app-1',
                    'git_branch' => $branch,
                    'environment_uuid' => $request->data()['environment_uuid'] ?? null,
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [[
                        'resource_uuid' => 'coolify-app-1',
                        'deployment_uuid' => 'dep-switch-1',
                        'message' => 'queued',
                    ]],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-switch-1')) {
                return Http::response([
                    'uuid' => 'dep-switch-1',
                    'status' => 'finished',
                    'commit' => 'switchsha',
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });
    }

    private function assertSecretsStayPrivate(Site $site): void
    {
        $site->refresh();
        $appKey = (string) $site->app_key_encrypted;
        $agentSecret = (string) $site->agent_secret_encrypted;

        $this->assertNotSame('', $appKey);
        $this->assertNotSame('', $agentSecret);
        $this->assertArrayNotHasKey('app_key_encrypted', $site->toArray());
        $this->assertArrayNotHasKey('agent_secret_encrypted', $site->toArray());

        foreach (AuditLog::query()->where('subject_id', $site->id)->get() as $log) {
            $encoded = json_encode([$log->before, $log->after, $log->action], JSON_UNESCAPED_SLASHES);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString($appKey, $encoded);
            $this->assertStringNotContainsString($agentSecret, $encoded);
        }

        $html = $this->get(route('ops.sites.show', $site))->assertOk()->getContent();
        $this->assertStringNotContainsString($appKey, $html);
        $this->assertStringNotContainsString($agentSecret, $html);
        $this->assertStringNotContainsString('test-coolify-token', $html);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
