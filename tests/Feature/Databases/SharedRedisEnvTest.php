<?php

namespace Tests\Feature\Databases;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Enums\SiteStatus;
use App\Models\CoolifyEnvDefault;
use App\Models\CoolifySetting;
use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\SiteRedisMover;
use App\Services\Databases\SiteRedisPlacement;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SharedRedisEnvTest extends TestCase
{
    use RefreshDatabase;

    private const REDIS_PASSWORD = 'site-redis-password-00000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        CoolifySetting::factory()->create(['base_url' => 'https://coolify.test', 'api_token' => 'test-coolify-token']);
    }

    public function test_sync_writes_redis_placement_for_a_shared_site(): void
    {
        $site = $this->sharedSite();
        $this->catalogRow();
        $this->fakeCoolify([['key' => 'APP_NAME', 'value' => 'Deamon'], ['key' => 'REDIS_HOST', 'value' => 'redis']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        foreach (SiteRedisPlacement::ENV_KEYS as $key) {
            $this->assertContains($key, $keys);
        }
        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return ($map['REDIS_HOST'] ?? null) === 'deamon-redis'
                && ($map['REDIS_USERNAME'] ?? null) === 'site_izyem'
                && ($map['REDIS_PASSWORD'] ?? null) === self::REDIS_PASSWORD
                && ($map['REDIS_DB'] ?? null) === '4'
                && ($map['REDIS_CACHE_DB'] ?? null) === '5'
                && ($map['REDIS_PREFIX'] ?? null) === 'site_izyem:';
        });
    }

    public function test_sync_writes_nothing_when_the_shared_env_is_already_right(): void
    {
        $site = $this->sharedSite();
        $this->catalogRow();
        $live = [['key' => 'APP_NAME', 'value' => 'Deamon']];
        foreach (SiteRedisPlacement::envFor($site) as $key => $value) {
            $live[] = ['key' => $key, 'value' => $value];
        }
        $this->fakeCoolify($live);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertSame([], array_values(array_intersect($keys, SiteRedisPlacement::ENV_KEYS)));
    }

    public function test_sync_never_writes_a_blank_redis_password(): void
    {
        $site = $this->sharedSite();
        $site->forceFill(['redis_password_encrypted' => null])->save();
        $this->catalogRow();
        $this->fakeCoolify([['key' => 'APP_NAME', 'value' => 'Deamon']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site->refresh(), CoolifyApplicationService::forSite($site));

        $this->assertContains('REDIS_HOST', $keys);
        $this->assertNotContains('REDIS_PASSWORD', $keys);
    }

    public function test_sync_leaves_redis_env_of_a_local_site_alone(): void
    {
        $site = $this->site();
        $this->catalogRow();
        $this->fakeCoolify([['key' => 'APP_NAME', 'value' => 'Deamon']]);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertSame([], array_values(array_intersect($keys, SiteRedisPlacement::ENV_KEYS)));
    }

    public function test_redis_placement_keys_are_bootstrap_keys_and_never_pruned(): void
    {
        foreach (SiteRedisPlacement::ENV_KEYS as $key) {
            $this->assertContains($key, CoolifyAppEnvSync::BOOTSTRAP_KEYS);
        }

        $site = $this->sharedSite();
        $this->catalogRow();
        $live = [['key' => 'APP_NAME', 'value' => 'Deamon'], ['key' => 'LEFTOVER', 'value' => 'x']];
        foreach (SiteRedisPlacement::envFor($site) as $key => $value) {
            $live[] = ['key' => $key, 'value' => $value];
        }
        $this->fakeCoolify($live);

        $keys = app(CoolifyAppEnvSync::class)->sync($site, CoolifyApplicationService::forSite($site));

        $this->assertContains('LEFTOVER', $keys);
        $this->assertSame([], array_values(array_intersect($keys, SiteRedisPlacement::ENV_KEYS)));
        foreach (SiteRedisPlacement::ENV_KEYS as $key) {
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/envs/env-'.md5($key)));
        }
    }

    public function test_prepare_reserves_a_pair_and_creates_the_user_without_switching(): void
    {
        $server = RedisServer::factory()->default()->create();
        $site = $this->site();
        $this->mock(RedisServerClient::class)->shouldReceive('ensureSiteUser')->once()
            ->withArgs(fn (RedisServer $s, Site $target): bool => $s->is($server) && $target->redis_db === 2);

        $result = app(SiteRedisMover::class)->prepare($site, $server);

        $this->assertSame(['db' => 2, 'cache_db' => 3, 'username' => 'site_izyem', 'prefix' => 'site_izyem:'], $result);
        $site->refresh();
        $this->assertFalse($site->usesSharedRedis(), 'prepare must not switch the site');
        $this->assertSame(SiteRedisPlacement::LOCAL_HOST, SiteRedisPlacement::envFor($site)['REDIS_HOST']);
        $this->assertTrue($site->auditLogs()->where('action', 'site.redis_prepared')->exists());
        Http::assertNothingSent();
    }

    public function test_switch_writes_env_restarts_and_marks_the_site_shared(): void
    {
        $server = RedisServer::factory()->default()->create();
        $site = $this->preparedSite($server);
        $this->fakeCoolify([]);

        $env = app(SiteRedisMover::class)->switchTo($site, $server, CoolifyApplicationService::forSite($site));

        $this->assertSame('deamon-redis', $env['REDIS_HOST']);
        $this->assertSame('4', $env['REDIS_DB']);
        $this->assertTrue($site->refresh()->usesSharedRedis());
        Http::assertSent(fn (Request $request): bool => ($this->bulkMap($request)['REDIS_PASSWORD'] ?? null) === self::REDIS_PASSWORD);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/restart'));

        $audit = $site->auditLogs()->where('action', 'site.redis_switched')->sole();
        $this->assertSame('***', $audit->after['REDIS_PASSWORD']);
        $this->assertStringNotContainsString(self::REDIS_PASSWORD, (string) json_encode($audit->toArray()));
    }

    public function test_switch_without_restart_only_writes_env(): void
    {
        $server = RedisServer::factory()->create();
        $site = $this->preparedSite($server);
        $this->fakeCoolify([]);

        app(SiteRedisMover::class)->switchTo($site, $server, CoolifyApplicationService::forSite($site), restart: false);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/restart'));
    }

    public function test_switch_refuses_a_site_that_was_not_prepared(): void
    {
        $server = RedisServer::factory()->create();
        $site = $this->site();
        $this->fakeCoolify([]);

        $this->expectException(RuntimeException::class);

        try {
            app(SiteRedisMover::class)->switchTo($site, $server, CoolifyApplicationService::forSite($site));
        } finally {
            $this->assertFalse($site->refresh()->usesSharedRedis());
            Http::assertNothingSent();
        }
    }

    public function test_back_to_local_restores_defaults_and_keeps_the_reservation(): void
    {
        $site = $this->sharedSite();
        $this->fakeCoolify([
            ['key' => 'REDIS_USERNAME', 'value' => 'site_izyem'],
            ['key' => 'REDIS_PASSWORD', 'value' => self::REDIS_PASSWORD],
        ]);

        $env = app(SiteRedisMover::class)->switchTo($site, null, CoolifyApplicationService::forSite($site));

        $this->assertSame([
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => '',
            'REDIS_PASSWORD' => '',
            'REDIS_DB' => '0',
            'REDIS_CACHE_DB' => '1',
            'REDIS_PREFIX' => 'deamon-database-',
        ], $env);

        $site->refresh();
        $this->assertFalse($site->usesSharedRedis());
        $this->assertSame(4, $site->redis_db, 'the pair stays reserved for the next move');
        $this->assertSame('site_izyem', $site->redis_username);

        Http::assertSent(function (Request $request): bool {
            $map = $this->bulkMap($request);

            return ($map['REDIS_HOST'] ?? null) === 'redis'
                && ($map['REDIS_PREFIX'] ?? null) === 'deamon-database-'
                && ! array_key_exists('REDIS_PASSWORD', $map);
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/envs/env-'.md5('REDIS_PASSWORD')));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/envs/env-'.md5('REDIS_USERNAME')));
    }

    public function test_a_second_move_reuses_the_reserved_pair(): void
    {
        $server = RedisServer::factory()->create();
        $site = $this->sharedSite($server);
        $site->forceFill(['redis_shared' => false])->save();
        $this->mock(RedisServerClient::class)->shouldReceive('ensureSiteUser')->once();

        $result = app(SiteRedisMover::class)->prepare($site->refresh(), $server);

        $this->assertSame(4, $result['db']);
    }

    private function catalogRow(): void
    {
        CoolifyEnvDefault::query()->create([
            'channel' => Channel::Main->value,
            'key' => 'APP_NAME',
            'value' => 'Deamon',
            'kind' => CoolifyEnvKind::Static,
            'is_secret' => false,
        ]);
    }

    /**
     * @param  list<array{key: string, value: string}>  $envs
     */
    private function fakeCoolify(array $envs): void
    {
        Http::fake(function (Request $request) use ($envs) {
            // The env sync reads the build pack before it prunes leftovers.
            if ($request->method() === 'GET' && preg_match('#/applications/[^/]+$#', $request->url()) === 1) {
                return Http::response(['uuid' => 'env-app-1', 'name' => 'izyem', 'build_pack' => 'dockercompose'], 200);
            }
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
        return Site::factory()->withSecrets()->create([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'env-app-1',
        ]);
    }

    private function preparedSite(RedisServer $server): Site
    {
        $site = $this->site();
        $site->forceFill([
            'redis_server_id' => $server->id,
            'redis_db' => 4,
            'redis_username' => 'site_izyem',
            'redis_password_encrypted' => self::REDIS_PASSWORD,
        ])->save();

        return $site->refresh();
    }

    private function sharedSite(?RedisServer $server = null): Site
    {
        $site = $this->preparedSite($server ?? RedisServer::factory()->create());
        $site->forceFill(['redis_shared' => true])->save();

        return $site->refresh();
    }
}
