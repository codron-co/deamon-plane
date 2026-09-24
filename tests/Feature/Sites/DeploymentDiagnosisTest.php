<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteAgentClient;
use App\Services\Sites\Diagnosis\DeploymentDiagnoser;
use App\Services\Sites\Diagnosis\DeploymentDiagnosis;
use App\Services\Sites\SiteAppHealthReport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeploymentDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'alaibjmbwyug8jpj1uigndk1';

    private const MYSQL_EXCERPT = '[{"output":"Container mysql-alaibjmbwyug8jpj1uigndk1-094151877919 Error dependency mysql failed to start\ndependency failed to start: container mysql-alaibjmbwyug8jpj1uigndk1-094151877919 exited (1)","type":"stderr"}]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_failed_row_is_diagnosed_automatically_with_container_logs_and_env_fix(): void
    {
        config(['ops.diagnosis.enabled' => true, 'ops.diagnosis.auto_fix' => true]);
        $site = $this->composeSite();
        $envPatched = false;

        Http::fake(function (Request $request) use (&$envPatched) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/applications/'.self::APP.'/logs')) {
                return Http::response(['logs' => "2026-09-19T09:47:18Z [ERROR] [Entrypoint]: Database is uninitialized and password option is not specified\nYou need to specify one of the following: MYSQL_ROOT_PASSWORD"], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP, 'build_pack' => 'dockercompose', 'docker_compose_location' => '/docker-compose.coolify.yml', 'status' => 'exited:unhealthy'], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/envs')) {
                return Http::response([
                    ['key' => 'DB_PASSWORD', 'value' => ''],
                    ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'],
                    ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => 'plane-agent-secret'],
                ], 200);
            }
            if ($request->method() === 'PATCH' && str_contains($url, '/envs/bulk')) {
                $envPatched = true;

                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        // Every failure path ends in a save; the model hook does the rest.
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => Channel::Alpha,
            'trigger' => DeploymentTrigger::Manual,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Coolify deploy’u başarısız oldu.',
            'log_excerpt' => self::MYSQL_EXCERPT,
            'finished_at' => now(),
        ]);

        $diagnosis = $deployment->fresh()->diagnosis();
        $this->assertInstanceOf(DeploymentDiagnosis::class, $diagnosis);
        $this->assertSame('mysql_no_root_password', $diagnosis->code);
        $this->assertStringContainsString('password option is not specified', (string) $diagnosis->containerLogs);
        $this->assertSame('sync_env', $diagnosis->autoFix['fix'] ?? null);
        $this->assertSame(DeploymentDiagnosis::AUTO_APPLIED, $diagnosis->autoFix['status'] ?? null);
        $this->assertTrue($envPatched, 'auto fix must have written the compose secrets to Coolify');
        $this->assertNotNull($diagnosis->diagnosedAt);

        $this->assertDatabaseHas('audit_logs', ['subject_id' => $site->id, 'action' => 'site.deploy_auto_fix']);

        // App health names the diagnosed code instead of the generic "deploy failed".
        $codes = collect($site->fresh()->appHealth()->issues)->map(fn ($issue) => $issue->code.'|'.($issue->key ?? ''))->all();
        $this->assertContains('deploy_diagnosed|mysql_no_root_password', $codes);
        $this->assertNotContains('deploy_failed|', $codes);
    }

    public function test_automatic_redeploy_is_skipped_on_a_site_that_follows_the_branch_head(): void
    {
        config(['ops.diagnosis.enabled' => true, 'ops.diagnosis.auto_fix' => true]);
        $site = $this->composeSite();
        Http::fake(fn () => Http::response(['error' => 'no coolify in this test'], 404));

        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'toomanyrequests: You have reached your pull rate limit.',
            'finished_at' => now(),
        ]);

        $diagnosis = $deployment->fresh()->diagnosis();
        $this->assertSame('registry_rate_limited', $diagnosis->code);
        $this->assertSame('redeploy', $diagnosis->autoFix['fix']);
        $this->assertSame(DeploymentDiagnosis::AUTO_SKIPPED, $diagnosis->autoFix['status']);
        $this->assertSame('follows_head', $diagnosis->autoFix['reason']);
        Http::assertNothingSent();
    }

    public function test_same_auto_fix_is_not_repeated_inside_the_window(): void
    {
        config(['ops.diagnosis.enabled' => true, 'ops.diagnosis.auto_fix' => true]);
        $site = $this->composeSite();
        Http::fake(fn () => Http::response(['error' => 'no coolify in this test'], 404));

        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Timed out waiting for Coolify deployment.',
            'finished_at' => now()->subMinutes(10),
            'diagnosis' => [
                'code' => 'coolify_timeout',
                'service' => 'coolify',
                'fixes' => ['sync_deployments'],
                'auto_fix' => ['fix' => 'sync_deployments', 'status' => DeploymentDiagnosis::AUTO_APPLIED, 'reason' => null, 'at' => now()->subMinutes(10)->toIso8601String()],
            ],
        ]);

        $second = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Timed out waiting for Coolify deployment.',
            'finished_at' => now(),
        ]);

        $diagnosis = $second->fresh()->diagnosis();
        $this->assertSame('coolify_timeout', $diagnosis->code);
        $this->assertSame(DeploymentDiagnosis::AUTO_SKIPPED, $diagnosis->autoFix['status']);
        $this->assertSame('repeated', $diagnosis->autoFix['reason']);
        Http::assertNothingSent();
    }

    public function test_refresh_refetches_logs_but_never_reruns_the_fix(): void
    {
        config(['ops.diagnosis.enabled' => true, 'ops.diagnosis.auto_fix' => true]);
        $site = $this->composeSite();
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            if (str_contains($request->url(), '/logs')) {
                $calls++;

                return Http::response(['logs' => [['name' => 'mysql-'.self::APP.'-1', 'logs' => '[ERROR] [InnoDB] Unable to lock ./ibdata1 error: 11']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'log_excerpt' => self::MYSQL_EXCERPT,
            'finished_at' => now(),
        ]);

        $first = $deployment->fresh()->diagnosis();
        $this->assertSame('mysql_locked', $first->code);
        $this->assertNull($first->autoFix, 'mysql_locked has no automatic fix');
        $this->assertStringContainsString('===== mysql-', (string) $first->containerLogs);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deployments.diagnose', [$site, $deployment]))
            ->assertRedirect(route('ops.sites.deployments.show', [$site, $deployment]));

        $this->assertSame(2, $calls);
        $this->assertSame('mysql_locked', $deployment->fresh()->diagnosis()->code);
    }

    public function test_deployment_page_shows_diagnosis_steps_commands_and_fix_buttons(): void
    {
        $site = $this->composeSite();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'f0fc3d1459e6',
            'error_message' => 'Coolify deploy’u başarısız oldu.',
            'log_excerpt' => self::MYSQL_EXCERPT,
            'finished_at' => now(),
        ]);

        // Disabled in phpunit.xml: classify explicitly, without Coolify.
        app(DeploymentDiagnoser::class)->diagnose($deployment, allowAutoFix: false);
        $deployment->refresh();
        $this->assertSame('mysql_exited', $deployment->diagnosis()->code);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.deployments.show', [$site, $deployment]))
            ->assertOk()
            ->assertSee('id="deployment-diagnosis"', false)
            ->assertSee('data-diagnosis-code="mysql_exited"', false)
            ->assertSee(__('deploy_diagnosis.codes.mysql_exited.title', ['exit' => 1]), false)
            ->assertSee('docker logs mysql-alaibjmbwyug8jpj1uigndk1-094151877919 --tail 80', false)
            ->assertSee('docker ps -a --filter name='.self::APP, false)
            ->assertSee('name="fix" value="redeploy"', false)
            ->assertSee(route('ops.sites.deployments.diagnose', [$site, $deployment]), false)
            ->assertSee('deploy-failure-triage.md#mysql_exited', false);

        // The list row leads with the diagnosis, not the raw Coolify sentence.
        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-deployment-headline', false)
            ->assertSee(__('deploy_diagnosis.codes.mysql_exited.title', ['exit' => 1]), false);
    }

    public function test_viewer_sees_diagnosis_without_refresh_or_fix_forms(): void
    {
        $site = $this->composeSite();
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Timed out waiting for Coolify deployment.',
            'finished_at' => now(),
        ]);
        app(DeploymentDiagnoser::class)->diagnose($deployment, allowAutoFix: false);

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->get(route('ops.sites.deployments.show', [$site, $deployment->fresh()]))
            ->assertOk()
            ->assertSee('data-diagnosis-code="coolify_timeout"', false)
            ->assertDontSee('name="fix" value="sync_deployments"', false)
            ->assertDontSee(route('ops.sites.deployments.diagnose', [$site, $deployment]), false);

        $this->actingAs($viewer)
            ->post(route('ops.sites.deployments.diagnose', [$site, $deployment]))
            ->assertForbidden();
    }

    public function test_placeholder_page_on_agent_path_is_reported_as_proxy_fallback_and_offers_restart(): void
    {
        $site = $this->composeSite();
        $site->forceFill(['agent_base_url' => 'https://wetsan.example'])->save();

        Http::fake(function (Request $request) use ($site) {
            if (str_contains($request->url(), '/internal/control/')) {
                return Http::response('<!doctype html><html><head><title>CodRon | Your domain is ready</title></head><body>This domain is successfully connected to Codron infrastructure.</body></html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/applications/'.self::APP.'/restart')) {
                return Http::response(['message' => 'Restarted.'], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP, 'build_pack' => 'dockercompose', 'docker_compose_location' => '/docker-compose.coolify.yml', 'status' => 'running:healthy'], 200);
            }
            if (str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_PASSWORD', 'value' => 'x'], ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'y'],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'], ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => (string) $site->agent_secret_encrypted],
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $result = app(SiteAgentClient::class)->health($site);
        $this->assertSame(AgentHealthReason::ProxyFallback, $result->reason);
        $this->assertSame(AgentHealthStatus::Unhealthy, $result->status);

        $site->forceFill(['last_health_at' => now(), 'last_health_payload' => $result->summary])->save();

        $issues = collect(SiteAppHealthReport::forDisplay($site->fresh())->issues);
        $this->assertTrue($issues->contains(fn ($issue) => $issue->code === 'app_not_running' && $issue->fix === 'restart_app'));
        $this->assertFalse($issues->contains(fn ($issue) => $issue->code === 'agent_unhealthy'));

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health.fix', $site), ['fix' => 'restart_app'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/applications/'.self::APP.'/restart'));
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $site->id, 'action' => 'site.app_restarted']);
    }

    public function test_live_inspect_flags_exited_compose_stack_unless_site_is_stopped(): void
    {
        $site = $this->composeSite();

        Http::fake(function (Request $request) use ($site) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP, 'build_pack' => 'dockercompose', 'docker_compose_location' => '/docker-compose.coolify.yml', 'status' => 'exited:unhealthy'], 200);
            }
            if (str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_PASSWORD', 'value' => 'x'], ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'y'],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'], ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => (string) $site->agent_secret_encrypted],
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())->postJson(route('ops.sites.app-health', $site))->assertOk();
        $codes = collect($site->fresh()->appHealth()->issues)->map(fn ($issue) => $issue->code)->all();
        $this->assertContains('app_not_running', $codes);

        $site->forceFill(['status' => SiteStatus::Stopped])->save();
        $this->actingAs($this->operator())->postJson(route('ops.sites.app-health', $site))->assertOk();
        $codes = collect($site->fresh()->appHealth()->issues)->map(fn ($issue) => $issue->code)->all();
        $this->assertNotContains('app_not_running', $codes);
    }

    public function test_rollback_last_good_pins_the_newest_finished_commit(): void
    {
        // The pin ends in a Coolify deploy; the sync queue would poll it forever.
        Queue::fake([PollDeploymentJob::class]);
        $site = $this->composeSite();
        Deployment::factory()->create(['site_id' => $site->id, 'status' => DeploymentStatus::Finished, 'commit_sha' => 'aaaa111', 'finished_at' => now()->subDays(2)]);
        Deployment::factory()->create(['site_id' => $site->id, 'status' => DeploymentStatus::Finished, 'commit_sha' => '30b5941abc', 'finished_at' => now()->subDay()]);
        Deployment::factory()->create(['site_id' => $site->id, 'status' => DeploymentStatus::Failed, 'commit_sha' => '59e5af0def', 'finished_at' => now(), 'error_message' => 'npm ERR! code ELIFECYCLE']);

        $pinned = null;
        Http::fake(function (Request $request) use (&$pinned, $site) {
            $url = $request->url();
            if ($request->method() === 'PATCH' && str_ends_with($url, '/applications/'.self::APP)) {
                $pinned = $request->data()['git_commit_sha'] ?? null;

                return Http::response(['uuid' => self::APP, 'git_commit_sha' => $pinned], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP, 'build_pack' => 'dockercompose', 'docker_compose_location' => '/docker-compose.coolify.yml', 'status' => 'running:healthy'], 200);
            }
            if (str_contains($url, '/envs/bulk')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/envs')) {
                return Http::response([
                    ['key' => 'DB_PASSWORD', 'value' => 'x'], ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'y'],
                    ['key' => 'APP_KEY', 'value' => (string) $site->app_key_encrypted], ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => (string) $site->agent_secret_encrypted],
                ], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-rollback-1', 'resource_uuid' => self::APP]]], 200);
            }
            if (str_contains($url, '/deployments/')) {
                return Http::response(['uuid' => 'dep-rollback-1', 'status' => 'in_progress'], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health.fix', $site), ['fix' => 'rollback_last_good'])
            ->assertOk();

        $this->assertSame('30b5941abc', $pinned);
    }

    private function composeSite(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'diagnosis-token',
        ]);

        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Alpha,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
            'primary_domain' => 'wetsan.example',
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
