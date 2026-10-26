<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\ProvisionSiteJob;
use App\Models\AuditLog;
use App\Models\CloudflareSetting;
use App\Models\CoolifySetting;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\SiteDatabasePlacement;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A new site is put on the default shared MySQL and Redis before its first build
 * (NewSitePlacement, called from SiteProvisioner::provisionOnCoolify).
 */
class ProvisionSharedServicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Coolify app's env as the fake holds it (PATCH /envs/bulk writes, GET /envs reads).
     *
     * @var array<string, string>
     */
    private array $env = [];

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

        CloudflareSetting::factory()->create([
            'account_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'api_token' => 'cf-provision-test-token',
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    public function test_provision_places_a_new_site_on_the_default_servers_before_the_first_deploy(): void
    {
        DatabaseServer::factory()->create(['name' => 'Old MySQL', 'host' => 'old-mysql']);
        $database = DatabaseServer::factory()->default()->create();
        $redis = RedisServer::factory()->default()->create();
        $this->fakeServerClients(databaseCalls: 1, redisCalls: 1);
        $this->fakeCoolify(['DB_PASSWORD' => '', 'MYSQL_ROOT_PASSWORD' => '']);

        $site = $this->draftSite();

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);

        $this->assertTrue($site->usesSharedDatabase());
        $this->assertSame($database->id, $site->database_server_id);
        $this->assertSame('site_izyem', $site->db_database);
        $this->assertSame('site_izyem', $site->db_username);

        $this->assertTrue($site->usesSharedRedis());
        $this->assertSame($redis->id, $site->redis_server_id);
        $this->assertSame(2, (int) $site->redis_db);

        // Plane made the DB password the shared user logs in with, and Coolify holds the same.
        $password = (string) $site->db_password_encrypted;
        $this->assertGreaterThanOrEqual(32, strlen($password));
        $this->assertSame($password, $this->env['DB_PASSWORD']);

        $this->assertSame('deamon-db-mysql', $this->env['DB_HOST']);
        $this->assertSame('3306', $this->env['DB_PORT']);
        $this->assertSame('site_izyem', $this->env['DB_DATABASE']);
        $this->assertSame('site_izyem', $this->env['DB_USERNAME']);
        $this->assertSame('deamon-redis', $this->env['REDIS_HOST']);
        $this->assertSame('site_izyem', $this->env['REDIS_USERNAME']);
        $this->assertSame((string) $site->redis_password_encrypted, $this->env['REDIS_PASSWORD']);
        $this->assertSame('2', $this->env['REDIS_DB']);
        $this->assertSame('3', $this->env['REDIS_CACHE_DB']);
        $this->assertSame('site_izyem:', $this->env['REDIS_PREFIX']);

        // DB_* and REDIS_* reached Coolify before the first deploy; nothing was restarted.
        $firstDeploy = $this->firstRequestIndex(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/deploy'));
        $dbWrite = $this->firstRequestIndex(fn (Request $r): bool => ($this->bulkMap($r)['DB_HOST'] ?? null) === 'deamon-db-mysql');
        $redisWrite = $this->firstRequestIndex(fn (Request $r): bool => ($this->bulkMap($r)['REDIS_HOST'] ?? null) === 'deamon-redis');
        $this->assertNotNull($firstDeploy);
        $this->assertNotNull($dbWrite);
        $this->assertNotNull($redisWrite);
        $this->assertLessThan($firstDeploy, $dbWrite);
        $this->assertLessThan($firstDeploy, $redisWrite);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/restart'));

        foreach (['site.database_prepared', 'site.database_switched', 'site.redis_prepared', 'site.redis_switched', 'site.shared_services_placed'] as $action) {
            $this->assertTrue($site->auditLogs()->where('action', $action)->exists(), $action);
        }

        $placed = AuditLog::query()->where('subject_id', $site->id)->where('action', 'site.shared_services_placed')->first();
        $this->assertSame('site_izyem', $placed?->after['database'] ?? null);
        $this->assertSame('site_izyem:', $placed?->after['redis_prefix'] ?? null);

        foreach (AuditLog::query()->where('subject_id', $site->id)->get() as $log) {
            $encoded = (string) json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($password, $encoded);
            $this->assertStringNotContainsString((string) $site->redis_password_encrypted, $encoded);
        }
    }

    public function test_reprovision_reuses_the_placement_without_duplicates(): void
    {
        DatabaseServer::factory()->default()->create();
        $redis = RedisServer::factory()->default()->create();
        $this->fakeServerClients(databaseCalls: 2, redisCalls: 2);
        $this->fakeCoolify(['DB_PASSWORD' => '']);

        $site = $this->draftSite();
        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $site->refresh();
        $password = (string) $site->db_password_encrypted;
        $redisPassword = (string) $site->redis_password_encrypted;
        $this->assertSame(SiteStatus::Active, $site->status);

        // A later failure leaves the site in error; the operator provisions again.
        $site->forceFill(['status' => SiteStatus::Error])->saveQuietly();

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame($password, (string) $site->db_password_encrypted);
        $this->assertSame($redisPassword, (string) $site->redis_password_encrypted);
        $this->assertSame(2, (int) $site->redis_db);
        $this->assertSame(1, Site::query()->where('redis_server_id', $redis->id)->count());
        $this->assertSame($password, $this->env['DB_PASSWORD']);
    }

    public function test_reprovision_keeps_the_live_db_password_of_an_existing_app(): void
    {
        DatabaseServer::factory()->default()->create();
        RedisServer::factory()->default()->create();
        $this->fakeServerClients(databaseCalls: 1, redisCalls: 1);
        $this->fakeCoolify(['DB_PASSWORD' => 'live-db-password-value-0000000001'], appUuid: 'existing-app');

        $site = $this->draftSite([
            'status' => SiteStatus::Error,
            'coolify_app_uuid' => 'existing-app',
        ]);

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('live-db-password-value-0000000001', (string) $site->db_password_encrypted);
        $this->assertTrue($site->usesSharedDatabase());
        Http::assertNotSent(fn (Request $r): bool => array_key_exists('DB_PASSWORD', $this->bulkMap($r)));
    }

    public function test_missing_default_database_server_stops_provision_before_anything_runs(): void
    {
        RedisServer::factory()->default()->create();
        DatabaseServer::factory()->create();
        $this->fakeServerClients(databaseCalls: 0, redisCalls: 0);
        Http::fake();

        $site = $this->draftSite();

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error', __('sites.provision_errors.no_default_database_server'));

        $this->assertSame(SiteStatus::Draft, $site->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_missing_default_redis_server_fails_the_job_with_an_audit_and_no_deploy(): void
    {
        DatabaseServer::factory()->default()->create();
        $this->fakeServerClients(databaseCalls: 0, redisCalls: 0);
        Http::fake();

        $site = $this->draftSite(['status' => SiteStatus::Provisioning]);

        ProvisionSiteJob::dispatchSync((string) $site->id);

        $site->refresh();
        $this->assertSame(SiteStatus::Error, $site->status);
        $this->assertNull($site->coolify_app_uuid);
        $this->assertFalse($site->usesSharedDatabase());

        $failed = AuditLog::query()->where('subject_id', $site->id)->where('action', 'site.provision_failed')->first();
        $this->assertNotNull($failed);
        $this->assertStringContainsString(__('sites.provision_errors.no_default_redis_server'), (string) ($failed->after['error'] ?? ''));

        $deployment = $site->deployments()->latest('id')->first();
        $this->assertSame(DeploymentStatus::Failed, $deployment?->status);
        Http::assertNothingSent();
    }

    public function test_a_site_that_ran_on_its_own_services_is_not_moved_by_provision(): void
    {
        DatabaseServer::factory()->default()->create();
        RedisServer::factory()->default()->create();
        $this->fakeServerClients(databaseCalls: 0, redisCalls: 0);
        Http::fake();

        $site = $this->draftSite([
            'status' => SiteStatus::Error,
            'coolify_app_uuid' => 'existing-app',
        ]);
        $site->deployments()->create([
            'channel' => Channel::Beta,
            'trigger' => DeploymentTrigger::Create,
            'status' => DeploymentStatus::Finished,
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error', __('sites.provision_errors.ran_on_local_services'));

        $site->refresh();
        $this->assertSame(SiteStatus::Error, $site->status);
        $this->assertFalse($site->usesSharedDatabase());
        $this->assertFalse($site->usesSharedRedis());
        Http::assertNothingSent();
    }

    private function fakeServerClients(int $databaseCalls, int $redisCalls): void
    {
        $this->mock(DatabaseServerClient::class, function (MockInterface $mock) use ($databaseCalls): void {
            $mock->shouldReceive('ensureSiteDatabase')
                ->times($databaseCalls)
                ->andReturnUsing(function (DatabaseServer $server, Site $site): array {
                    $this->assertTrue($server->is_default);
                    $this->assertNotSame('', (string) $site->db_password_encrypted);

                    return SiteDatabasePlacement::namesFor($site);
                });
        });

        // Like the real client: the site's Redis password is generated once, on the first prepare.
        $this->mock(RedisServerClient::class, function (MockInterface $mock) use ($redisCalls): void {
            $mock->shouldReceive('ensureSiteUser')
                ->times($redisCalls)
                ->andReturnUsing(function (RedisServer $server, Site $site): void {
                    if (blank($site->redis_password_encrypted)) {
                        $site->forceFill(['redis_password_encrypted' => Str::password(40, symbols: false)])->save();
                    }
                });
        });
    }

    /**
     * @param  array<string, string>  $env  the app's env before Plane writes anything
     */
    private function fakeCoolify(array $env, string $appUuid = 'coolify-app-1'): void
    {
        $this->env = $env;

        Http::fake(function (Request $request) use ($appUuid) {
            if ($cf = $this->cloudflareResponse($request)) {
                return $cf;
            }

            $url = $request->url();
            $method = $request->method();
            $app = [
                'uuid' => $appUuid,
                'name' => 'deamon-izyem',
                'git_branch' => 'beta',
                'build_pack' => 'dockercompose',
                'docker_compose_location' => '/docker-compose.coolify.yml',
                'docker_compose_domains' => [['name' => 'app', 'domain' => 'https://shop.izyem.example.test']],
            ];

            if ($method === 'POST' && str_contains($url, '/applications/')) {
                return Http::response($app, 201);
            }

            if ($method === 'GET' && str_contains($url, '/envs')) {
                $rows = [];
                foreach ($this->env as $key => $value) {
                    $rows[] = ['key' => $key, 'value' => $value, 'is_preview' => false, 'uuid' => 'env-'.md5($key)];
                }

                return Http::response($rows, 200);
            }

            if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
                $this->env = array_merge($this->env, $this->bulkMap($request));

                return Http::response([], 200);
            }

            if (str_contains($url, '/applications/'.$appUuid) && in_array($method, ['GET', 'PATCH'], true)) {
                return Http::response($app, 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [['resource_uuid' => $appUuid, 'deployment_uuid' => 'dep-1', 'message' => 'queued']],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
                return Http::response(['uuid' => 'dep-1', 'status' => 'finished', 'commit' => 'abc123def'], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });
    }

    /**
     * Covering-zone fake: the zone exists, DNS writes succeed.
     */
    private function cloudflareResponse(Request $request): ?PromiseInterface
    {
        $url = $request->url();
        if (! str_contains($url, 'api.cloudflare.com')) {
            return null;
        }

        $zone = [
            'id' => 'zone-shop-izyem-example-test',
            'name' => 'shop.izyem.example.test',
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];

        if ($request->method() === 'GET' && preg_match('#/client/v4/zones/[a-z0-9-]+$#', $url) === 1) {
            return Http::response(['success' => true, 'result' => $zone], 200);
        }

        if ($request->method() === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            return Http::response(['success' => true, 'result' => [$zone]], 200);
        }

        if ($request->method() === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (in_array($request->method(), ['POST', 'PUT'], true) && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'rec-1', 'type' => $request->data()['type'] ?? 'A']], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }

    /**
     * @return array<string, string>
     */
    private function bulkMap(Request $request): array
    {
        if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
            return [];
        }

        $map = [];
        foreach ($request->data()['data'] ?? [] as $row) {
            if (is_array($row) && isset($row['key'])) {
                $map[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $map;
    }

    /**
     * @param  callable(Request): bool  $match
     */
    private function firstRequestIndex(callable $match): ?int
    {
        foreach (Http::recorded() as $index => [$request]) {
            if ($match($request)) {
                return $index;
            }
        }

        return null;
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

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
