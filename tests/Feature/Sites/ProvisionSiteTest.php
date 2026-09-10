<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionSiteTest extends TestCase
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

    public function test_operator_happy_path_creates_compose_app_and_marks_active(): void
    {
        $this->fakeCoolifyHappyPath();

        $operator = $this->user(OpsRole::Operator);
        $site = $this->draftSite();

        $this->actingAs($operator)
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();

        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('coolify-app-1', $site->coolify_app_uuid);
        $this->assertNotNull($site->app_key_encrypted);
        $this->assertNotNull($site->agent_secret_encrypted);
        $this->assertStringStartsWith('base64:', $site->app_key_encrypted);
        $this->assertSame('https://shop.izyem.example.test', $site->agent_base_url);

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'channel' => Channel::Beta->value,
            'trigger' => DeploymentTrigger::Create->value,
            'coolify_deployment_uuid' => 'dep-1',
            'status' => DeploymentStatus::Finished->value,
            'commit_sha' => 'abc123def',
            'requested_by' => $operator->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.provision_started',
            'subject_id' => $site->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.provision_succeeded',
            'subject_id' => $site->id,
        ]);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://coolify.test/api/v1/applications/public'
                && ($body['build_pack'] ?? null) === 'dockercompose'
                && ($body['docker_compose_location'] ?? null) === '/docker-compose.coolify.yml'
                && ($body['git_repository'] ?? null) === 'https://github.com/codron-co/deamon.git'
                && ($body['git_branch'] ?? null) === 'beta'
                && ($body['project_uuid'] ?? null) === 'proj_test'
                && ($body['server_uuid'] ?? null) === 'srv_test'
                && ($body['name'] ?? null) === 'deamon-izyem'
                && ! array_key_exists('fqdn', $body)
                && ($body['docker_compose_domains'] ?? null) === [
                    ['name' => 'app', 'domain' => 'https://shop.izyem.example.test'],
                ]
                && empty($body['instant_deploy'])
                && ! array_key_exists('docker_compose_raw', $body);
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/applications/coolify-app-1/envs/bulk')) {
                return false;
            }

            $pairs = $request->data()['data'] ?? [];
            $keys = array_column($pairs, 'key');

            return $keys === ['APP_KEY', 'DEAMON_SITE_NAME']
                && collect($pairs)->firstWhere('key', 'DEAMON_SITE_NAME')['value'] === 'Izyem';
        });

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/coolify-app-1'
                && ($body['docker_compose_domains'] ?? null) === [
                    ['name' => 'app', 'domain' => 'https://shop.izyem.example.test'],
                ]
                && ! array_key_exists('fqdn', $body)
                && ($body['force_domain_override'] ?? null) === false;
        });

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://coolify.test/api/v1/deploy')
                && str_contains($request->url(), 'uuid=coolify-app-1');
        });

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), '/applications/dockercompose');
        });

        $this->assertSecretsStayPrivate($site);
    }

    public function test_coolify_422_field_errors_appear_in_session_and_audit(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/applications/')) {
                return Http::response([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'server_uuid' => ['The selected server uuid is invalid.'],
                    ],
                ], 422);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Validation failed.', $error);
        $this->assertStringContainsString('server_uuid', $error);

        $failed = AuditLog::query()
            ->where('subject_id', $site->id)
            ->where('action', 'site.provision_failed')
            ->first();
        $this->assertNotNull($failed);
        $this->assertStringContainsString('server_uuid', (string) ($failed->after['error'] ?? ''));

        $deployment = $site->deployments()->latest('id')->first();
        $this->assertNotNull($deployment);
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('Validation failed.', (string) $deployment->error_message);
        $this->assertStringContainsString('server_uuid', (string) $deployment->error_message);
        $this->assertStringContainsString('Coolify errors:', (string) $deployment->error_message);
    }

    public function test_preflight_rejects_server_missing_from_list_servers(): void
    {
        CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'is_default' => true,
            'default_server_uuid' => 'srv_test',
            'default_project_uuid' => 'proj_test',
        ]);

        Http::fake([
            'https://coolify.test/api/v1/servers' => Http::response([
                ['uuid' => 'other-server', 'name' => 'other'],
            ], 200),
        ]);

        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsStringIgnoringCase('sunucu', $error);
        $this->assertSame(SiteStatus::Draft, $site->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/applications/'));
    }

    public function test_coolify_create_failure_marks_error_and_audits(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/applications/')) {
                return Http::response(['message' => 'compose create failed'], 500);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $operator = $this->user(OpsRole::Operator);
        $site = $this->draftSite();

        $this->actingAs($operator)
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(SiteStatus::Error, $site->status);
        $this->assertNull($site->coolify_app_uuid);
        $this->assertNotNull($site->app_key_encrypted);
        $this->assertNotNull($site->agent_secret_encrypted);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.provision_started',
            'subject_id' => $site->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.provision_failed',
            'subject_id' => $site->id,
        ]);

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'trigger' => DeploymentTrigger::Create->value,
            'status' => DeploymentStatus::Failed->value,
        ]);

        $row = $site->fresh()->deployments()->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('compose create failed', (string) $row->error_message);

        $failed = AuditLog::query()
            ->where('subject_id', $site->id)
            ->where('action', 'site.provision_failed')
            ->first();

        $this->assertNotNull($failed);
        $this->assertSame('error', $failed->after['status'] ?? null);
        $this->assertStringContainsString('compose create failed', (string) ($failed->after['error'] ?? ''));

        $this->assertSecretsStayPrivate($site);
    }

    public function test_error_retry_reuses_existing_coolify_app_uuid(): void
    {
        $this->fakeCoolifyHappyPath();

        $site = $this->draftSite([
            'status' => SiteStatus::Error,
            'coolify_app_uuid' => 'existing-app',
            'app_key_encrypted' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'agent_secret_encrypted' => 'existing-agent-secret-value-not-for-logs',
        ]);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();

        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('existing-app', $site->coolify_app_uuid);

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'POST' && str_contains($request->url(), '/applications/');
        });

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/applications/existing-app/envs/bulk');
        });
    }

    public function test_viewer_cannot_provision(): void
    {
        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.provision', $site))
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertDontSee('action="'.route('ops.sites.provision', $site).'"', false);

        $site->refresh();
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertNull($site->coolify_app_uuid);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'site.provision_started',
            'subject_id' => $site->id,
        ]);
    }

    public function test_active_site_cannot_be_provisioned_again(): void
    {
        $site = $this->draftSite([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'already-there',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('already-there', $site->coolify_app_uuid);
        Http::assertNothingSent();
    }

    public function test_poll_failure_stores_coolify_message_errors_and_truncated_logs(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && str_contains($url, '/applications/')) {
                return Http::response([
                    'uuid' => 'coolify-app-1',
                    'name' => 'deamon-izyem',
                    'git_branch' => 'beta',
                    'build_pack' => 'dockercompose',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                ], 201);
            }

            if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
                return Http::response([], 200);
            }

            if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
                return Http::response([
                    'uuid' => 'coolify-app-1',
                    'name' => 'deamon-izyem',
                    'docker_compose_domains' => [
                        ['name' => 'app', 'domain' => 'https://shop.izyem.example.test'],
                    ],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [[
                        'resource_uuid' => 'coolify-app-1',
                        'deployment_uuid' => 'dep-fail',
                        'message' => 'queued',
                    ]],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-fail')) {
                return Http::response([
                    'uuid' => 'dep-fail',
                    'status' => 'failed',
                    'commit' => 'deadbeef',
                    'message' => 'Validation failed.',
                    'errors' => ['fqdn' => ['This field is not allowed.']],
                    'logs' => "APP_KEY=base64:SHOULD_NOT_APPEAR\nCoolify compose build exploded on service app",
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $deployment = $site->fresh()->deployments()->latest('id')->first();
        $this->assertNotNull($deployment);
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('deadbeef', $deployment->commit_sha);
        $this->assertStringContainsString('Validation failed.', (string) $deployment->error_message);
        $this->assertStringContainsString('This field is not allowed.', (string) $deployment->error_message);
        $this->assertStringContainsString('compose build exploded', (string) $deployment->log_excerpt);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', (string) $deployment->log_excerpt);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', (string) $deployment->error_message);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.deployments.show', [$site, $deployment]))
            ->assertOk()
            ->assertSee('This field is not allowed.', false)
            ->assertSee('compose build exploded', false)
            ->assertDontSee('SHOULD_NOT_APPEAR', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftSite(array $overrides = []): Site
    {
        return Site::factory()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'channel' => Channel::Beta,
            'status' => SiteStatus::Draft,
            'coolify_server_uuid' => 'srv_test',
        ], $overrides));
    }

    private function fakeCoolifyHappyPath(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && str_contains($url, '/applications/dockercompose')) {
                return Http::response(['error' => 'deprecated path must not be used'], 500);
            }

            if ($method === 'POST' && str_contains($url, '/applications/')) {
                return Http::response([
                    'uuid' => 'coolify-app-1',
                    'name' => 'deamon-izyem',
                    'git_branch' => 'beta',
                    'build_pack' => 'dockercompose',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                ], 201);
            }

            if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
                return Http::response([], 200);
            }

            if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
                return Http::response([
                    'uuid' => str_contains($url, 'existing-app') ? 'existing-app' : 'coolify-app-1',
                    'name' => 'deamon-izyem',
                    'docker_compose_domains' => [
                        ['name' => 'app', 'domain' => 'https://shop.izyem.example.test'],
                    ],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                $appUuid = str_contains($url, 'existing-app') ? 'existing-app' : 'coolify-app-1';

                return Http::response([
                    'deployments' => [[
                        'resource_uuid' => $appUuid,
                        'deployment_uuid' => 'dep-1',
                        'message' => 'queued',
                    ]],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
                return Http::response([
                    'uuid' => 'dep-1',
                    'status' => 'finished',
                    'commit' => 'abc123def',
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

        $html = $this->get(route('ops.sites.edit', $site))->assertOk()->getContent();
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
