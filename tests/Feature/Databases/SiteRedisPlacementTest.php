<?php

namespace Tests\Feature\Databases;

use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Databases\SiteRedisPlacement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SiteRedisPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_prefix_come_from_the_slug(): void
    {
        $site = Site::factory()->create(['slug' => 'basut-silo']);

        $this->assertSame(['username' => 'site_basut_silo', 'prefix' => 'site_basut_silo:'], SiteRedisPlacement::namesFor($site));
    }

    public function test_long_slugs_stay_within_64_characters_and_distinct(): void
    {
        $a = Site::factory()->create(['slug' => str_repeat('beyazoglu-otomotiv-', 4).'anadolu']);
        $b = Site::factory()->create(['slug' => str_repeat('beyazoglu-otomotiv-', 4).'avrupa']);

        $na = SiteRedisPlacement::namesFor($a);
        $nb = SiteRedisPlacement::namesFor($b);

        $this->assertLessThanOrEqual(64, strlen($na['username']));
        $this->assertNotSame($na['username'], $nb['username']);
        $this->assertTrue(SiteRedisPlacement::isSafeName($na['username']));
        $this->assertSame($na['username'].':', $na['prefix']);
    }

    public function test_a_local_site_gets_exactly_todays_cms_defaults(): void
    {
        $site = Site::factory()->create();

        $this->assertSame([
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => '',
            'REDIS_PASSWORD' => '',
            'REDIS_DB' => '0',
            'REDIS_CACHE_DB' => '1',
            'REDIS_PREFIX' => 'deamon-database-',
        ], SiteRedisPlacement::envFor($site));
        $this->assertSame(SiteRedisPlacement::ENV_KEYS, array_keys(SiteRedisPlacement::envFor($site)));
    }

    public function test_a_shared_site_points_at_the_server_with_its_own_pair_user_and_prefix(): void
    {
        $server = RedisServer::factory()->create(['host' => 'deamon-redis', 'port' => 6379]);
        $site = $this->reserved('izyem', $server, 6, shared: true);

        $this->assertTrue($site->usesSharedRedis());
        $this->assertSame([
            'REDIS_HOST' => 'deamon-redis',
            'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => 'site_izyem',
            'REDIS_PASSWORD' => 'site-redis-password',
            'REDIS_DB' => '6',
            'REDIS_CACHE_DB' => '7',
            'REDIS_PREFIX' => 'site_izyem:',
        ], SiteRedisPlacement::envFor($site));
    }

    public function test_a_reserved_but_not_switched_site_stays_local(): void
    {
        $site = $this->reserved('izyem', RedisServer::factory()->create(), 2, shared: false);

        $this->assertFalse($site->usesSharedRedis());
        $this->assertSame('redis', SiteRedisPlacement::envFor($site)['REDIS_HOST']);
    }

    public function test_allocation_takes_the_lowest_free_even_pair_per_server(): void
    {
        $server = RedisServer::factory()->create();
        $other = RedisServer::factory()->create(['name' => 'Other']);

        $this->assertSame(2, SiteRedisPlacement::nextFreeDb($server));

        $this->reserved('a', $server, 2);
        $this->reserved('b', $server, 6);
        $this->reserved('c', $other, 4);
        $this->assertSame(4, SiteRedisPlacement::nextFreeDb($server));

        $deleted = $this->reserved('d', $server, 4);
        $deleted->delete();
        $this->assertSame(8, SiteRedisPlacement::nextFreeDb($server), 'soft-deleted sites keep their pair');
        $this->assertSame(2, SiteRedisPlacement::nextFreeDb($other));
    }

    public function test_reserve_assigns_once_and_reuses_on_the_same_server(): void
    {
        $server = RedisServer::factory()->create();
        $this->reserved('first', $server, 2);
        $site = Site::factory()->create(['slug' => 'izyem']);

        SiteRedisPlacement::reserve($site, $server);
        $this->assertSame(4, $site->refresh()->redis_db);
        $this->assertSame('site_izyem', $site->redis_username);
        $this->assertFalse($site->usesSharedRedis());

        SiteRedisPlacement::reserve($site, $server);
        $this->assertSame(4, $site->refresh()->redis_db);
    }

    public function test_the_last_pair_is_1022_and_a_full_server_is_an_error(): void
    {
        $server = RedisServer::factory()->create();
        $rows = [];
        for ($db = 2; $db <= 1020; $db += 2) {
            $rows[] = $db;
        }
        foreach ($rows as $db) {
            $this->reserved('s'.$db, $server, $db);
        }

        $this->assertSame(1022, SiteRedisPlacement::nextFreeDb($server));
        $this->reserved('last', $server, 1022);

        $this->expectException(RuntimeException::class);
        SiteRedisPlacement::nextFreeDb($server);
    }

    public function test_a_db_pair_cannot_be_given_to_two_sites(): void
    {
        $server = RedisServer::factory()->create();
        $this->reserved('a', $server, 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->reserved('b', $server, 2);
    }

    public function test_redis_secrets_are_encrypted_and_hidden(): void
    {
        $server = RedisServer::factory()->create(['admin_password' => 'very-secret-redis-admin']);
        $site = $this->reserved('izyem', $server, 2);

        $this->assertNotSame('very-secret-redis-admin', $server->getRawOriginal('admin_password'));
        $this->assertSame('very-secret-redis-admin', $server->fresh()->admin_password);
        $this->assertArrayNotHasKey('admin_password', $server->toArray());

        $this->assertNotSame('site-redis-password', $site->getRawOriginal('redis_password_encrypted'));
        $this->assertSame('site-redis-password', $site->fresh()->redis_password_encrypted);
        $this->assertArrayNotHasKey('redis_password_encrypted', $site->toArray());
    }

    private function reserved(string $slug, RedisServer $server, int $db, bool $shared = false): Site
    {
        $site = Site::factory()->create(['slug' => $slug]);
        $site->forceFill([
            'redis_server_id' => $server->id,
            'redis_db' => $db,
            'redis_username' => 'site_'.str_replace('-', '_', $slug),
            'redis_password_encrypted' => 'site-redis-password',
            'redis_shared' => $shared,
        ])->save();

        return $site->refresh();
    }
}
