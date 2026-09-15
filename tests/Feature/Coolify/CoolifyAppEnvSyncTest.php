<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifyEnvDefault;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        // Removed from the CMS catalog in 1.2.25, so a site's old value is pruned.
        $this->assertContains('CONTROL_PLANE_HOST_ALLOWLIST', $keys);
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

    /**
     * @param  list<array{key: string, value: string, uuid?: string}>  $envs
     */
    private function fakeEnvs(array $envs): void
    {
        Http::fake(function (Request $request) use ($envs) {
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
