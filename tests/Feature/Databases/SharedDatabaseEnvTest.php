<?php

namespace Tests\Feature\Databases;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifySetting;
use App\Models\DatabaseServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\SiteDatabaseMover;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SharedDatabaseEnvTest extends TestCase
{
    use RefreshDatabase;

    private const DB_PASSWORD = 'live-db-password-value-0000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        CoolifySetting::factory()->create(['base_url' => 'https://coolify.test', 'api_token' => 'test-coolify-token']);
    }

    public function test_sync_writes_db_placement_for_a_shared_site(): void
    {
        $site = $this->sharedSite();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => self::DB_PASSWORD], ['key' => 'DB_HOST', 'value' => 'mysql']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('DB_HOST', $keys);
        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return ($map['DB_HOST'] ?? null) === 'deamon-db-mysql'
                && ($map['DB_DATABASE'] ?? null) === 'site_izyem'
                && ($map['DB_USERNAME'] ?? null) === 'site_izyem';
        });
    }

    public function test_sync_leaves_db_env_of_a_local_site_alone(): void
    {
        $site = $this->site();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => self::DB_PASSWORD]]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('DB_HOST', $keys);
        $this->assertNotContains('DB_DATABASE', $keys);
    }

    public function test_placement_keys_are_never_pruned(): void
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
            $this->assertContains($key, CoolifyAppEnvSync::BOOTSTRAP_KEYS);
        }
    }

    public function test_switch_writes_env_restarts_and_records_the_placement(): void
    {
        $server = DatabaseServer::factory()->default()->create();
        $site = $this->site();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => self::DB_PASSWORD]]);

        $env = app(SiteDatabaseMover::class)->switchTo($site, $server, CoolifyApplicationService::forSite($site));

        $this->assertSame('deamon-db-mysql', $env['DB_HOST']);
        $site->refresh();
        $this->assertTrue($site->usesSharedDatabase());
        $this->assertSame('site_izyem', $site->db_database);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/restart'));
        $this->assertTrue($site->auditLogs()->where('action', 'site.database_switched')->exists());
    }

    public function test_switch_back_to_local_restores_compose_defaults(): void
    {
        $site = $this->sharedSite();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => self::DB_PASSWORD]]);

        $env = app(SiteDatabaseMover::class)->switchTo($site, null, CoolifyApplicationService::forSite($site));

        $this->assertSame(['DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_DATABASE' => 'deamon', 'DB_USERNAME' => 'deamon'], $env);
        $this->assertFalse($site->refresh()->usesSharedDatabase());
    }

    public function test_prepare_refuses_when_planes_password_copy_differs_from_coolify(): void
    {
        $server = DatabaseServer::factory()->create();
        $site = $this->site();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => 'a-different-live-password-000000001']]);
        $this->mock(DatabaseServerClient::class)->shouldNotReceive('ensureSiteDatabase');

        $this->expectException(RuntimeException::class);

        app(SiteDatabaseMover::class)->prepare($site, $server, CoolifyApplicationService::forSite($site));
    }

    public function test_prepare_creates_the_site_database_with_the_live_password(): void
    {
        $server = DatabaseServer::factory()->create();
        $site = $this->site();
        $this->fakeCoolify([['key' => 'DB_PASSWORD', 'value' => self::DB_PASSWORD]]);
        $this->mock(DatabaseServerClient::class)
            ->shouldReceive('ensureSiteDatabase')->once()
            ->andReturn(['database' => 'site_izyem', 'username' => 'site_izyem']);

        $names = app(SiteDatabaseMover::class)->prepare($site, $server, CoolifyApplicationService::forSite($site));

        $this->assertSame('site_izyem', $names['database']);
        $this->assertFalse($site->refresh()->usesSharedDatabase(), 'prepare must not switch the site');
    }

    /**
     * @param  list<array{key: string, value: string}>  $envs
     */
    private function fakeCoolify(array $envs): void
    {
        Http::fake(function (Request $request) use ($envs) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response(array_map(static fn (array $row): array => $row + ['uuid' => 'env-'.md5($row['key'])], $envs), 200);
            }
            if (str_contains($request->url(), '/envs') || str_contains($request->url(), '/restart')) {
                return Http::response(['message' => 'ok'], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });
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

    private function site(): Site
    {
        $site = Site::factory()->withSecrets()->create([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'env-app-1',
        ]);
        $site->forceFill(['db_password_encrypted' => self::DB_PASSWORD])->save();

        return $site;
    }

    private function sharedSite(): Site
    {
        $server = DatabaseServer::factory()->create();
        $site = $this->site();
        $site->forceFill(['database_server_id' => $server->id, 'db_database' => 'site_izyem', 'db_username' => 'site_izyem'])->save();

        return $site->refresh();
    }
}
