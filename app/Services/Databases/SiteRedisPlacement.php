<?php

namespace App\Services\Databases;

use App\Models\RedisServer;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Where a site's Redis lives and the REDIS_* env the CMS compose reads.
 *
 * The local values are exactly what the CMS uses today with its own `redis`
 * service (DB 0 for queue/session/locks, DB 1 for cache, prefix
 * `deamon-database-`, no login), so a local site never sees a change. A site on
 * a shared server is kept apart three ways: its own key prefix, its own ACL user
 * limited to that prefix, and its own DB pair (N, N+1).
 */
final class SiteRedisPlacement
{
    public const LOCAL_HOST = 'redis';

    public const LOCAL_PORT = 6379;

    public const LOCAL_DB = 0;

    public const LOCAL_CACHE_DB = 1;

    public const LOCAL_PREFIX = 'deamon-database-';

    /** DB 0/1 are left to the server itself; the shared server runs with `databases 1024`. */
    public const FIRST_DB = 2;

    public const LAST_DB = 1022;

    /**
     * Env keys Plane writes for the placement. They are bootstrap keys: never pruned.
     *
     * @var list<string>
     */
    public const ENV_KEYS = [
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_USERNAME',
        'REDIS_PASSWORD',
        'REDIS_DB',
        'REDIS_CACHE_DB',
        'REDIS_PREFIX',
    ];

    private const USERNAME_MAX = 64;

    /**
     * ACL user name and key prefix a site gets on a shared server, from its slug.
     *
     * @return array{username: string, prefix: string}
     */
    public static function namesFor(Site $site): array
    {
        $base = 'site_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $site->slug)), '_');

        $username = strlen($base) <= self::USERNAME_MAX
            ? $base
            : substr($base, 0, self::USERNAME_MAX - 7).'_'.substr(hash('sha256', (string) $site->slug), 0, 6);

        return ['username' => $username, 'prefix' => $username.':'];
    }

    /**
     * The prefix of the site's keys on the shared server (from the stored user name once
     * assigned, so a later slug change cannot strand the keys).
     */
    public static function prefixFor(Site $site): string
    {
        $username = trim((string) $site->redis_username);

        return $username !== '' ? $username.':' : self::namesFor($site)['prefix'];
    }

    /**
     * @return array{REDIS_HOST: string, REDIS_PORT: string, REDIS_USERNAME: string, REDIS_PASSWORD: string, REDIS_DB: string, REDIS_CACHE_DB: string, REDIS_PREFIX: string}
     */
    public static function envFor(Site $site): array
    {
        $server = $site->usesSharedRedis() ? $site->redisServer : null;

        if (! $server instanceof RedisServer) {
            return [
                'REDIS_HOST' => self::LOCAL_HOST,
                'REDIS_PORT' => (string) self::LOCAL_PORT,
                'REDIS_USERNAME' => '',
                'REDIS_PASSWORD' => '',
                'REDIS_DB' => (string) self::LOCAL_DB,
                'REDIS_CACHE_DB' => (string) self::LOCAL_CACHE_DB,
                'REDIS_PREFIX' => self::LOCAL_PREFIX,
            ];
        }

        $db = (int) $site->redis_db;

        return [
            'REDIS_HOST' => (string) $server->host,
            'REDIS_PORT' => (string) $server->port,
            'REDIS_USERNAME' => (string) $site->redis_username,
            'REDIS_PASSWORD' => (string) ($site->redis_password_encrypted ?? ''),
            'REDIS_DB' => (string) $db,
            'REDIS_CACHE_DB' => (string) ($db + 1),
            'REDIS_PREFIX' => self::prefixFor($site),
        ];
    }

    /**
     * The lowest free even DB number N on the server (the site gets N and N+1).
     */
    public static function nextFreeDb(RedisServer $server): int
    {
        // Soft-deleted sites still hold their row in the unique index, and their keys.
        $taken = Site::withTrashed()
            ->where('redis_server_id', $server->id)
            ->whereNotNull('redis_db')
            ->pluck('redis_db')
            ->map(fn (mixed $db): int => (int) $db)
            ->flip();

        for ($db = self::FIRST_DB; $db <= self::LAST_DB; $db += 2) {
            if (! $taken->has($db)) {
                return $db;
            }
        }

        throw new RuntimeException("Redis server {$server->name} has no free DB pair left.");
    }

    /**
     * Give the site a DB pair and user name on the server, or keep the ones it already
     * holds there. Does not switch the site.
     */
    public static function reserve(Site $site, RedisServer $server): void
    {
        if ($site->redis_server_id === $server->id && $site->redis_db !== null && filled($site->redis_username)) {
            return;
        }

        if ($site->usesSharedRedis()) {
            throw new RuntimeException('Site runs on another Redis server; move it back to local first.');
        }

        $username = self::namesFor($site)['username'];

        for ($attempt = 0; ; $attempt++) {
            $site->forceFill([
                'redis_server_id' => $server->id,
                'redis_db' => self::nextFreeDb($server),
                'redis_username' => $username,
            ]);

            try {
                $site->save();
                $site->unsetRelation('redisServer');

                return;
            } catch (UniqueConstraintViolationException $e) {
                // Another prepare took the same pair between read and write.
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    public static function isSafeName(string $name): bool
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $name) === 1;
    }
}
