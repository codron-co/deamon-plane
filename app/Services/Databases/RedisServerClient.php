<?php

namespace App\Services\Databases;

use App\Models\RedisServer;
use App\Models\Site;
use Illuminate\Support\Str;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Talks to a shared Redis server with the admin login (phpredis).
 *
 * Server-level work only: read INFO, create or align a site's ACL user, count or
 * delete one site's keys. Sites themselves connect as their own ACL user, which
 * cannot see another site's prefix and cannot run FLUSHDB/FLUSHALL/KEYS.
 */
class RedisServerClient
{
    public const CONNECT_TIMEOUT = 5.0;

    public const READ_TIMEOUT = 10.0;

    /** Keys per SCAN page and per UNLINK call. */
    public const BATCH = 500;

    /**
     * @return array{version: string, uptime_seconds: int, connected_clients: int, used_memory: int, maxmemory: int, evicted_keys: int, keyspace: array<int, array{keys: int, expires: int}>}
     */
    public function probe(RedisServer $server): array
    {
        return $this->withAdmin($server, function (Redis $redis): array {
            $info = $redis->info();
            if (! is_array($info)) {
                throw new RuntimeException('INFO returned no data.');
            }

            $keyspace = [];
            foreach ($info as $key => $value) {
                if (preg_match('/^db(\d+)$/', (string) $key, $m) === 1) {
                    $keyspace[(int) $m[1]] = self::parseKeyspace($value);
                }
            }
            ksort($keyspace);

            return [
                'version' => (string) ($info['redis_version'] ?? ''),
                'uptime_seconds' => (int) ($info['uptime_in_seconds'] ?? 0),
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'used_memory' => (int) ($info['used_memory'] ?? 0),
                'maxmemory' => (int) ($info['maxmemory'] ?? 0),
                'evicted_keys' => (int) ($info['evicted_keys'] ?? 0),
                'keyspace' => $keyspace,
            ];
        });
    }

    /**
     * Probe and store the result on the server row.
     */
    public function refresh(RedisServer $server): RedisServer
    {
        $server->forceFill([
            'last_probe_at' => now(),
            'last_probe_payload' => $this->probe($server),
        ])->save();

        return $server;
    }

    /**
     * Create (or align) the site's ACL user and persist the ACL file. The password is
     * Plane's copy on the site row, generated once when empty. Idempotent: re-running
     * re-applies the same password and rule.
     */
    public function ensureSiteUser(RedisServer $server, Site $site): void
    {
        $username = trim((string) $site->redis_username);
        if (! SiteRedisPlacement::isSafeName($username)) {
            throw new RuntimeException("Unsafe Redis user name: {$username}");
        }

        $password = (string) ($site->redis_password_encrypted ?? '');
        if ($password === '') {
            $password = Str::password(40, symbols: false);
            $site->forceFill(['redis_password_encrypted' => $password])->save();
        }

        $args = self::aclSetUserArgs($username, $password, SiteRedisPlacement::prefixFor($site));

        $this->withAdmin($server, secrets: [$password], callback: function (Redis $redis) use ($args): void {
            self::expectOk($redis->rawCommand('ACL', 'SETUSER', ...$args), 'ACL SETUSER');
            self::expectOk($redis->rawCommand('ACL', 'SAVE'), 'ACL SAVE');
        });
    }

    /**
     * Arguments after `ACL SETUSER`: the user may only touch keys and channels under
     * its prefix, and none of the commands that could reach or wipe other sites.
     *
     * @return list<string>
     */
    public static function aclSetUserArgs(string $username, string $password, string $prefix): array
    {
        if (! SiteRedisPlacement::isSafeName($username)) {
            throw new RuntimeException("Unsafe Redis user name: {$username}");
        }
        if (preg_match('/^[a-z0-9_]{1,64}:$/', $prefix) !== 1) {
            throw new RuntimeException("Unsafe Redis key prefix: {$prefix}");
        }
        if ($password === '' || preg_match('/\s/', $password) === 1) {
            throw new RuntimeException('Redis password must be non-empty and contain no whitespace.');
        }

        return [
            $username,
            'on',
            'resetpass',
            '>'.$password,
            'resetkeys',
            '~'.$prefix.'*',
            'resetchannels',
            '&'.$prefix.'*',
            'nocommands',
            '+@all',
            '-@dangerous',
            '-flushdb',
            '-flushall',
            '-keys',
        ];
    }

    /**
     * Keys under the site's prefix in its two DBs (SCAN, never KEYS).
     *
     * @return array{db: int, cache_db: int}
     */
    public function siteKeyCounts(Site $site): array
    {
        [$server, $db, $match] = $this->siteTarget($site);

        return $this->withAdmin($server, function (Redis $redis) use ($db, $match): array {
            $counts = [];
            foreach ([$db, $db + 1] as $number) {
                $count = 0;
                $this->scanEach($redis, $number, $match, function (array $keys) use (&$count): void {
                    $count += count($keys);
                });
                $counts[] = $count;
            }

            return ['db' => $counts[0], 'cache_db' => $counts[1]];
        });
    }

    /**
     * Delete every key under the site's prefix in its two DBs: what `cache:clear` would
     * do on a private Redis, without FLUSHDB. Returns the number of keys deleted.
     */
    public function flushSite(Site $site): int
    {
        [$server, $db, $match] = $this->siteTarget($site);

        return $this->withAdmin($server, function (Redis $redis) use ($db, $match): int {
            $deleted = 0;
            foreach ([$db, $db + 1] as $number) {
                $this->scanEach($redis, $number, $match, function (array $keys) use ($redis, &$deleted): void {
                    $deleted += (int) $redis->unlink($keys);
                });
            }

            return $deleted;
        });
    }

    /**
     * @return array{0: RedisServer, 1: int, 2: string}
     */
    private function siteTarget(Site $site): array
    {
        $server = $site->redisServer;
        if (! $server instanceof RedisServer || $site->redis_db === null || blank($site->redis_username)) {
            throw new RuntimeException('Site has no Redis DB pair on a shared server; run prepare first.');
        }

        $prefix = SiteRedisPlacement::prefixFor($site);
        if (preg_match('/^[a-z0-9_]{1,64}:$/', $prefix) !== 1) {
            throw new RuntimeException("Unsafe Redis key prefix: {$prefix}");
        }

        return [$server, (int) $site->redis_db, $prefix.'*'];
    }

    /**
     * @param  callable(list<string>): void  $each
     */
    private function scanEach(Redis $redis, int $db, string $match, callable $each): void
    {
        if ($redis->select($db) !== true) {
            throw new RuntimeException("SELECT {$db} failed.");
        }

        $iterator = null;
        // SCAN_RETRY: phpredis keeps scanning past empty pages and returns false at the end.
        while (($keys = $redis->scan($iterator, $match, self::BATCH)) !== false) {
            if ($keys !== []) {
                $each(array_values(array_map('strval', $keys)));
            }
        }
    }

    /**
     * @return array{keys: int, expires: int}
     */
    private static function parseKeyspace(mixed $value): array
    {
        $fields = [];
        if (is_array($value)) {
            $fields = $value;
        } elseif (is_string($value)) {
            foreach (explode(',', $value) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '0');
                $fields[trim($k)] = $v;
            }
        }

        return ['keys' => (int) ($fields['keys'] ?? 0), 'expires' => (int) ($fields['expires'] ?? 0)];
    }

    private static function expectOk(mixed $reply, string $command): void
    {
        if ($reply !== true && ! (is_string($reply) && strtoupper($reply) === 'OK')) {
            throw new RuntimeException("{$command} was refused.");
        }
    }

    /**
     * @template T
     *
     * @param  callable(Redis): T  $callback
     * @param  list<string>  $secrets  extra values to keep out of error messages
     * @return T
     */
    protected function withAdmin(RedisServer $server, callable $callback, array $secrets = []): mixed
    {
        $adminPassword = (string) $server->admin_password;
        $redis = null;

        try {
            $redis = $this->connect($server);

            return $callback($redis);
        } catch (Throwable $e) {
            throw new RedisServerException($e->getMessage(), [$adminPassword, ...$secrets]);
        } finally {
            try {
                $redis?->close();
            } catch (Throwable) {
                // Closing a broken connection is not worth an error of its own.
            }
        }
    }

    protected function connect(RedisServer $server): Redis
    {
        if (! class_exists(Redis::class)) {
            throw new RuntimeException('The phpredis extension is not installed.');
        }

        $redis = new Redis;
        if (! $redis->connect((string) $server->host, (int) $server->port, self::CONNECT_TIMEOUT, null, 0, self::READ_TIMEOUT)) {
            throw new RuntimeException("Cannot connect to {$server->host}:{$server->port}.");
        }

        $user = trim((string) $server->admin_username);
        $password = (string) $server->admin_password;
        if (! $redis->auth($user !== '' ? [$user, $password] : $password)) {
            throw new RuntimeException('Admin login was refused.');
        }

        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        return $redis;
    }
}
