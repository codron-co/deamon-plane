<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifyEnvDefault;
use App\Models\CoolifySetting;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CoolifyAppEnvSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['app.url' => 'https://plane.codron.co']);

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
        ]);
    }

    public function test_channel_catalog_fills_empty_secrets_and_site_tokens_without_service_keys(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'APP_KEY', 'value' => (string) $site->app_key_encrypted],
            ['key' => 'DEAMON_SITE_NAME', 'value' => $site->name],
            ['key' => 'DB_PASSWORD', 'value' => ''],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertContains('DB_PASSWORD', $keys);
        $this->assertContains('DEAMON_CHANNEL', $keys);
        $this->assertContains('APP_ENV', $keys);
        $this->assertContains('CONTROL_PLANE_AGENT_SECRET', $keys);
        $this->assertNotContains('CONTROL_PLANE_HOST_ALLOWLIST', $keys);
        $this->assertNotContains('APP_KEY', $keys);
        $this->assertNotContains('DEAMON_SITE_NAME', $keys);
        $this->assertNotContains('SERVICE_URL_APP', $keys);
        $this->assertNotContains('APP_URL', $keys);
        $this->assertNotContains('APP_TIMEZONE', $keys);
        $this->assertNotContains('DB_HOST', $keys);

        Http::assertSent(function (Request $request) use ($site): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
                return false;
            }

            $map = $this->bulkMap($request);

            return $map['DEAMON_CHANNEL'] === Channel::Main->value
                && $map['APP_ENV'] === 'production'
                && $map['CONTROL_PLANE_AGENT_SECRET'] === (string) $site->agent_secret_encrypted
                && ! array_key_exists('CONTROL_PLANE_HOST_ALLOWLIST', $map)
                && strlen((string) $map['MYSQL_ROOT_PASSWORD']) >= 32
                && strlen((string) $map['DB_PASSWORD']) >= 32
                && ! array_key_exists('SERVICE_URL_APP', $map)
                && ! array_key_exists('APP_URL', $map);
        });
    }

    public function test_prunes_legacy_keys_but_keeps_coolify_injects_and_does_not_rotate_secrets(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'APP_KEY', 'value' => (string) $site->app_key_encrypted, 'uuid' => 'env-app-key'],
            ['key' => 'DEAMON_SITE_NAME', 'value' => $site->name, 'uuid' => 'env-site-name'],
            ['key' => 'DEAMON_CHANNEL', 'value' => Channel::Main->value, 'uuid' => 'env-channel'],
            ['key' => 'APP_ENV', 'value' => 'production', 'uuid' => 'env-app-env'],
            ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => (string) $site->agent_secret_encrypted, 'uuid' => 'env-agent'],
            ['key' => 'CONTROL_PLANE_HOST_ALLOWLIST', 'value' => 'plane.codron.co', 'uuid' => 'env-host'],
            ['key' => 'DB_PASSWORD', 'value' => 'already-set-db-password-value', 'uuid' => 'env-db'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'already-set-root-password-xx', 'uuid' => 'env-root'],
            ['key' => 'DEAMON_PLATFORM_MAIL_HOST', 'value' => 'smtp.hostinger.com', 'uuid' => 'env-mail-host'],
            ['key' => 'SERVICE_URL_APP', 'value' => 'https://example.test', 'uuid' => 'env-service'],
            ['key' => 'APP_URL', 'value' => 'https://example.test', 'uuid' => 'env-app-url'],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('DEAMON_PLATFORM_MAIL_HOST', $keys);
        // A bootstrap key (ADR-12) stays even when the CMS catalog no longer lists it.
        $this->assertNotContains('CONTROL_PLANE_HOST_ALLOWLIST', $keys);
        $this->assertNotContains('SERVICE_URL_APP', $keys);
        $this->assertNotContains('APP_URL', $keys);
        $this->assertNotContains('DB_PASSWORD', $keys);
        $this->assertNotContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertNotContains('APP_KEY', $keys);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-mail-host'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-service'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/envs/bulk'));
    }

    public function test_does_not_rotate_filled_db_passwords_but_replaces_example_placeholders(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'already-set-db-password-value'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'change-me-generate-strong-mysql-root-password'],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('DB_PASSWORD', $keys);
        $this->assertContains('MYSQL_ROOT_PASSWORD', $keys);

        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return $map !== []
                && ! array_key_exists('DB_PASSWORD', $map)
                && strlen((string) ($map['MYSQL_ROOT_PASSWORD'] ?? '')) >= 32;
        });
    }

    public function test_uses_the_catalog_of_the_site_channel_and_writes_channel_specific_rows(): void
    {
        CoolifyEnvDefault::query()->forChannel(Channel::Alpha)->delete();
        CoolifyEnvDefault::factory()->create([
            'channel' => Channel::Alpha,
            'key' => 'ALPHA_ONLY',
            'value' => 'yes',
        ]);

        $site = $this->site(['channel' => Channel::Alpha]);
        $this->fakeEnvs([]);

        $this->assertSame(Channel::Alpha, app(CoolifyAppEnvSync::class)->channelFor($site));

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertSame(['ALPHA_ONLY'], $keys);
    }

    public function test_static_rows_are_corrected_and_static_matches_are_left_alone(): void
    {
        CoolifyEnvDefault::factory()->create([
            'channel' => Channel::Main,
            'key' => 'FEATURE_FLAG',
            'value' => 'on',
        ]);

        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'FEATURE_FLAG', 'value' => 'off'],
            ['key' => 'DB_PASSWORD', 'value' => 'filled-db-password-value-xx'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'filled-root-password-value-xx'],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('FEATURE_FLAG', $keys);
        Http::assertSent(fn (Request $request): bool => ($this->bulkMap($request)['FEATURE_FLAG'] ?? null) === 'on');
    }

    public function test_empty_catalog_without_github_credentials_writes_nothing(): void
    {
        CoolifyEnvDefault::query()->delete();
        $site = $this->site();
        $this->fakeEnvs([['key' => 'DB_PASSWORD', 'value' => '']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertSame([], $keys);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_a_catalog_without_bootstrap_keys_never_deletes_them(): void
    {
        $site = $this->site();
        CoolifyEnvDefault::query()->forChannel(Channel::Main)
            ->whereIn('key', CoolifyAppEnvSync::BOOTSTRAP_KEYS)
            ->delete();
        $this->assertTrue(CoolifyEnvDefault::query()->forChannel(Channel::Main)->exists());

        $envs = [];
        foreach (CoolifyAppEnvSync::BOOTSTRAP_KEYS as $key) {
            $envs[] = ['key' => $key, 'value' => 'live-value-'.strtolower($key), 'uuid' => 'env-'.strtolower($key)];
        }
        $envs[] = ['key' => 'LEGACY_SETTING', 'value' => 'old', 'uuid' => 'env-legacy'];
        $this->fakeEnvs($envs);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('LEGACY_SETTING', $keys);
        foreach (CoolifyAppEnvSync::BOOTSTRAP_KEYS as $key) {
            $this->assertNotContains($key, $keys);
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-legacy'));
        Http::assertNotSent(function (Request $request): bool {
            if ($request->method() !== 'DELETE') {
                return false;
            }

            foreach (CoolifyAppEnvSync::BOOTSTRAP_KEYS as $key) {
                if (str_contains($request->url(), '/envs/env-'.strtolower($key))) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * CMS 1.2.86 drops MYSQL_ROOT_PASSWORD from `.env.production.example` (no site
     * MySQL container any more): nothing is generated or required, the live value is
     * copied onto the site row first and then pruned like any other leftover.
     */
    public function test_a_catalog_without_mysql_root_password_prunes_it_after_keeping_a_copy(): void
    {
        $site = $this->site(['last_health_at' => now()]);
        CoolifyEnvDefault::query()->where('key', 'MYSQL_ROOT_PASSWORD')->delete();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'live-db-password-value-0000000001', 'uuid' => 'env-db'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'live-root-password-value-00000001', 'uuid' => 'env-root'],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertNotContains('DB_PASSWORD', $keys);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-root'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-db'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && array_key_exists('MYSQL_ROOT_PASSWORD', $this->bulkMap($request)));
        $this->assertSame('live-root-password-value-00000001', $site->refresh()->mysql_root_password_encrypted);
    }

    public function test_a_catalog_without_mysql_root_password_never_generates_it(): void
    {
        $site = $this->site();
        CoolifyEnvDefault::query()->where('key', 'MYSQL_ROOT_PASSWORD')->delete();
        $this->fakeEnvs([['key' => 'DB_PASSWORD', 'value' => '']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('DB_PASSWORD', $keys);
        $this->assertNotContains('MYSQL_ROOT_PASSWORD', $keys);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && array_key_exists('MYSQL_ROOT_PASSWORD', $this->bulkMap($request)));
        $this->assertNull($site->refresh()->mysql_root_password_encrypted);
    }

    /**
     * A Docker Image app has no compose file: QUEUE_CONNECTION, SESSION_DRIVER, ... are
     * Coolify env rows outside the catalog (deamon-test, 2026-10-26: 20 keys pruned).
     */
    public function test_a_docker_image_app_keeps_env_outside_the_catalog_but_still_gets_catalog_and_placement_keys(): void
    {
        $site = $this->site(['slug' => 'test', 'channel' => Channel::Alpha]);
        $site->forceFill([
            'database_server_id' => DatabaseServer::factory()->create()->id,
            'db_database' => 'site_test',
            'db_username' => 'site_test',
            'redis_server_id' => RedisServer::factory()->create()->id,
            'redis_db' => 4,
            'redis_username' => 'site_test',
            'redis_password_encrypted' => 'redis-password-value-00000000001',
            'redis_shared' => true,
        ])->save();
        $site->refresh();
        $this->assertTrue($site->usesSharedDatabase());
        $this->assertTrue($site->usesSharedRedis());
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'live-db-password-value-0000000001', 'uuid' => 'env-db'],
            ['key' => 'QUEUE_CONNECTION', 'value' => 'redis', 'uuid' => 'env-queue'],
            ['key' => 'SESSION_DRIVER', 'value' => 'redis', 'uuid' => 'env-session'],
            ['key' => 'CACHE_STORE', 'value' => 'redis', 'uuid' => 'env-cache'],
            ['key' => 'DB_CONNECTION', 'value' => 'mysql', 'uuid' => 'env-db-connection'],
            ['key' => 'NGINX_PORT', 'value' => '80', 'uuid' => 'env-nginx-port'],
        ], 'dockerimage');

        Log::spy();

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        foreach (['QUEUE_CONNECTION', 'SESSION_DRIVER', 'CACHE_STORE', 'DB_CONNECTION', 'NGINX_PORT'] as $key) {
            $this->assertNotContains($key, $keys);
        }
        $this->assertContains('DEAMON_CHANNEL', $keys);
        $this->assertContains('DB_HOST', $keys);
        $this->assertContains('REDIS_HOST', $keys);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return $request->method() === 'PATCH'
                && ($map['DEAMON_CHANNEL'] ?? null) === Channel::Alpha->value
                && array_key_exists('DB_HOST', $map)
                && array_key_exists('REDIS_HOST', $map);
        });
        Log::shouldHaveReceived('info')->with('coolify.env_prune_skipped_dockerimage', Mockery::on(
            fn (array $context): bool => $context['build_pack'] === 'dockerimage'
                && in_array('QUEUE_CONNECTION', $context['keys'], true),
        ))->once();
    }

    public function test_a_compose_app_still_prunes_env_outside_the_catalog(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'live-db-password-value-0000000001', 'uuid' => 'env-db'],
            ['key' => 'QUEUE_CONNECTION', 'value' => 'redis', 'uuid' => 'env-queue'],
        ], 'dockercompose');

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('QUEUE_CONNECTION', $keys);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-queue'));
    }

    public function test_a_dockerfile_app_still_prunes_env_outside_the_catalog(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'QUEUE_CONNECTION', 'value' => 'redis', 'uuid' => 'env-queue'],
        ], 'dockerfile');

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('QUEUE_CONNECTION', $keys);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-queue'));
    }

    public function test_nothing_is_pruned_when_the_build_pack_cannot_be_read(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => ''],
            ['key' => 'QUEUE_CONNECTION', 'value' => 'redis', 'uuid' => 'env-queue'],
        ], null);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('QUEUE_CONNECTION', $keys);
        $this->assertContains('DB_PASSWORD', $keys);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_the_app_is_not_read_when_nothing_is_left_to_prune(): void
    {
        $site = $this->site();
        $this->fakeEnvs([['key' => 'DB_PASSWORD', 'value' => '']]);

        app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET'
            && preg_match('#/applications/[^/]+$#', $request->url()) === 1);
    }

    public function test_live_db_passwords_are_captured_on_the_site_row(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'live-db-password-value-0000000001'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'live-root-password-value-00000001'],
        ]);

        app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $site->refresh();
        $this->assertSame('live-db-password-value-0000000001', $site->db_password_encrypted);
        $this->assertSame('live-root-password-value-00000001', $site->mysql_root_password_encrypted);
        $this->assertArrayNotHasKey('db_password_encrypted', $site->toArray());
    }

    public function test_blank_db_passwords_are_restored_from_the_site_copy_not_regenerated(): void
    {
        $site = $this->site(['last_health_at' => now()]);
        $site->forceFill([
            'db_password_encrypted' => 'kept-db-password-value-00000000001',
            'mysql_root_password_encrypted' => 'kept-root-password-value-000000001',
        ])->save();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => ''],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('DB_PASSWORD', $keys);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
                return false;
            }
            $map = $this->bulkMap($request);

            return $map['DB_PASSWORD'] === 'kept-db-password-value-00000000001'
                && $map['MYSQL_ROOT_PASSWORD'] === 'kept-root-password-value-000000001';
        });
    }

    public function test_a_site_that_already_ran_without_a_copy_gets_no_new_db_password(): void
    {
        $site = $this->site(['last_health_at' => now()]);
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => ''],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('DB_PASSWORD', $keys);
        $this->assertNotContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertNull($site->refresh()->db_password_encrypted);
    }

    public function test_a_new_site_keeps_a_copy_of_the_passwords_it_is_given(): void
    {
        $site = $this->site();
        $this->fakeEnvs([['key' => 'DB_PASSWORD', 'value' => '']]);

        app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $copy = (string) $site->refresh()->db_password_encrypted;
        $this->assertSame(40, strlen($copy));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && ($this->bulkMap($request)['DB_PASSWORD'] ?? null) === $copy);
    }

    /**
     * @param  list<array{key: string, value: string, uuid?: string}>  $envs
     * @param  string|null  $buildPack  null: GET /applications/{uuid} fails
     */
    private function fakeEnvs(array $envs, ?string $buildPack = 'dockercompose'): void
    {
        Http::fake(function (Request $request) use ($envs, $buildPack) {
            if ($request->method() === 'GET' && preg_match('#/applications/[^/]+$#', $request->url()) === 1) {
                return $buildPack === null
                    ? Http::response(['message' => 'boom'], 500)
                    : Http::response(['uuid' => 'env-app-1', 'name' => 'izyem', 'build_pack' => $buildPack], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response(array_map(static function (array $row): array {
                    return [
                        'key' => $row['key'],
                        'value' => $row['value'],
                        'uuid' => $row['uuid'] ?? 'env-'.md5($row['key']),
                    ];
                }, $envs), 200);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/envs/bulk')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/envs/')) {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });
    }

    /**
     * @return array<string, string>
     */
    private function bulkMap(Request $request): array
    {
        $map = [];
        foreach ($request->data()['data'] ?? [] as $row) {
            if (is_array($row) && isset($row['key'])) {
                $map[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'name' => 'Izyem',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'env-app-1',
        ], $overrides));
    }
}
