<?php

namespace App\Services\Databases;

use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Moves a site between its own compose Redis and a shared server.
 *
 * Two steps, so the key copy can happen in between (tools/ops/move-site-redis.sh):
 *   prepare  → DB pair, user name and ACL user exist; the site still runs on its own Redis.
 *   switch   → Plane marks the site shared, writes REDIS_* env to Coolify, restarts the app.
 * Switching back to local only rewrites env and clears the flag: the DB pair and user stay
 * reserved on the server so a second move reuses them, and the old compose Redis is never touched.
 */
class SiteRedisMover
{
    public function __construct(
        private readonly RedisServerClient $client,
    ) {}

    /**
     * @return array{db: int, cache_db: int, username: string, prefix: string}
     */
    public function prepare(Site $site, RedisServer $server): array
    {
        SiteRedisPlacement::reserve($site, $server);
        $this->client->ensureSiteUser($server, $site);

        $result = [
            'db' => (int) $site->redis_db,
            'cache_db' => (int) $site->redis_db + 1,
            'username' => (string) $site->redis_username,
            'prefix' => SiteRedisPlacement::prefixFor($site),
        ];

        $this->audit($site, 'site.redis_prepared', null, ['server' => $server->name] + $result);

        return $result;
    }

    /**
     * @return array<string, string> the REDIS_* env written (REDIS_PASSWORD included)
     */
    public function switchTo(Site $site, ?RedisServer $server, CoolifyApplicationService $coolify, bool $restart = true): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new RuntimeException('Site has no Coolify application.');
        }

        $before = SiteRedisPlacement::envFor($site);

        if ($server !== null) {
            if ($site->redis_server_id !== $server->id || $site->redis_db === null || blank($site->redis_username)) {
                throw new RuntimeException('Site is not prepared on this Redis server; run prepare first.');
            }
            if (blank($site->redis_password_encrypted)) {
                throw new RuntimeException('Site has no Redis password; run prepare first.');
            }
            $site->forceFill(['redis_shared' => true]);
        } else {
            $site->forceFill(['redis_shared' => false]);
        }

        $site->save();
        $site->unsetRelation('redisServer');

        $env = SiteRedisPlacement::envFor($site);
        $this->writeEnv($coolify, $uuid, $env);

        if ($restart) {
            $coolify->restartApplication($uuid);
        }

        $this->audit($site, 'site.redis_switched', self::masked($before), self::masked($env) + ['restarted' => $restart ? 'yes' : 'no']);
        Log::info('site.redis_switched', ['site_id' => $site->id, 'site_slug' => $site->slug, 'redis_host' => $env['REDIS_HOST']]);

        return $env;
    }

    /**
     * Blank values (REDIS_USERNAME/REDIS_PASSWORD of a local site) are removed rather
     * than written empty: the compose default is empty anyway, and Coolify's env API
     * does not reliably accept an empty value.
     *
     * @param  array<string, string>  $env
     */
    private function writeEnv(CoolifyApplicationService $coolify, string $uuid, array $env): void
    {
        $coolify->updateEnvs($uuid, array_filter($env, static fn (string $value): bool => $value !== ''));

        $blank = array_keys(array_filter($env, static fn (string $value): bool => $value === ''));
        if ($blank === []) {
            return;
        }

        foreach ($coolify->listEnvs($uuid) as $row) {
            $envUuid = trim((string) ($row->uuid ?? ''));
            if (! $row->isPreview && $envUuid !== '' && in_array($row->key, $blank, true)) {
                $coolify->deleteEnv($uuid, $envUuid);
            }
        }
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function masked(array $env): array
    {
        if (($env['REDIS_PASSWORD'] ?? '') !== '') {
            $env['REDIS_PASSWORD'] = '***';
        }

        return $env;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Site $site, string $action, ?array $before, ?array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
        ]);
    }
}
