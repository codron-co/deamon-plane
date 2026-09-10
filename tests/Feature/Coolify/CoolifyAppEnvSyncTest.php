<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Enums\CoolifyEnvPack;
use App\Enums\SiteStatus;
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

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
        ]);
    }

    public function test_compose_fills_empty_mysql_secrets_and_static_timezone_without_touching_service_keys(): void
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
        $this->assertContains('APP_TIMEZONE', $keys);
        $this->assertContains('DB_HOST', $keys);
        $this->assertNotContains('SERVICE_URL_APP', $keys);

        Http::assertSent(function (Request $request) use ($site): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
                return false;
            }

            $map = $this->bulkMap($request);

            return $map['APP_TIMEZONE'] === 'Europe/Istanbul'
                && $map['DB_HOST'] === 'mysql'
                && $map['DEAMON_CHANNEL'] === Channel::Main->value
                && $map['APP_ENV'] === 'production'
                && $map['CONTROL_PLANE_AGENT_SECRET'] === (string) $site->agent_secret_encrypted
                && strlen((string) $map['MYSQL_ROOT_PASSWORD']) >= 32
                && strlen((string) $map['DB_PASSWORD']) >= 32
                && ! array_key_exists('SERVICE_URL_APP', $map);
        });
    }

    public function test_compose_does_not_rotate_filled_db_passwords_but_replaces_example_placeholders(): void
    {
        $site = $this->site();
        $this->fakeEnvs([
            ['key' => 'DB_PASSWORD', 'value' => 'already-set-db-password-value'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'change-me-generate-strong-mysql-root-password'],
            ['key' => 'APP_TIMEZONE', 'value' => 'UTC'],
        ]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('DB_PASSWORD', $keys);
        $this->assertContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertContains('APP_TIMEZONE', $keys);

        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return $map !== []
                && ! array_key_exists('DB_PASSWORD', $map)
                && strlen((string) ($map['MYSQL_ROOT_PASSWORD'] ?? '')) >= 32
                && $map['APP_TIMEZONE'] === 'Europe/Istanbul';
        });
    }

    public function test_dockerfile_pack_does_not_write_mysql_root_or_overwrite_external_db_host(): void
    {
        $site = $this->site([
            'notes' => '[import] '.Site::DOCKERFILE_BUILD_PACK_MARKER.': leftover',
        ]);
        $this->fakeEnvs([
            ['key' => 'DB_HOST', 'value' => '10.0.0.9'],
            ['key' => 'DB_PASSWORD', 'value' => 'external-db-secret'],
        ]);

        $this->assertSame(CoolifyEnvPack::Dockerfile, app(CoolifyAppEnvSync::class)->packFor($site));

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertNotContains('MYSQL_ROOT_PASSWORD', $keys);
        $this->assertNotContains('DB_HOST', $keys);
        $this->assertNotContains('DB_PASSWORD', $keys);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/envs/bulk')) {
                return false;
            }

            $map = $this->bulkMap($request);

            return ! array_key_exists('MYSQL_ROOT_PASSWORD', $map)
                && ! array_key_exists('DB_HOST', $map)
                && ! array_key_exists('DB_PASSWORD', $map);
        });
    }

    /**
     * @param  list<array{key: string, value: string}>  $envs
     */
    private function fakeEnvs(array $envs): void
    {
        Http::fake(function (Request $request) use ($envs) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response($envs, 200);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/envs/bulk')) {
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
