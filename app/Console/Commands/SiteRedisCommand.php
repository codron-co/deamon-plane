<?php

namespace App\Console\Commands;

use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\SiteRedisMover;
use App\Services\Databases\SiteRedisPlacement;
use Illuminate\Console\Command;
use Throwable;

/**
 * Redis placement of one site, for tools/ops/move-site-redis.sh and operators.
 *
 *   status   where the site's Redis is, the REDIS_* env it should have, its target DB pair
 *   prepare  reserve the DB pair and create the ACL user on the shared server (site keeps running)
 *   switch   point the site at the shared server and restart it
 *   local    point the site back at its own compose Redis and restart it (pair stays reserved)
 *   flush    delete the site's cache keys on the shared server (replaces cache:clear, FLUSHDB is
 *            denied); --all also empties the sessions/queue DB, refused while the site uses it
 *
 * Output lines are KEY=value so the shell script can read them. REDIS_PASSWORD is
 * printed as *** unless --show-secret is given.
 */
class SiteRedisCommand extends Command
{
    private const ACTIONS = ['status', 'prepare', 'switch', 'local', 'flush'];

    protected $signature = 'ops:site-redis
        {action : status|prepare|switch|local|flush}
        {site : site slug, id, primary domain or Coolify app uuid}
        {--server= : Redis server name or id (default: the default server)}
        {--no-restart : write env only; restart later}
        {--show-secret : print REDIS_PASSWORD / TARGET_PASSWORD instead of ***}
        {--keys : status also counts the site\'s keys on the shared server (SCAN)}
        {--all : flush also empties the sessions/queue DB (only while the site is not on the shared server)}';

    protected $description = 'Prepare, switch, revert or flush a site\'s Redis placement (shared Redis)';

    public function handle(SiteRedisMover $mover, RedisServerClient $client): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Unknown action: '.$action);

            return self::INVALID;
        }

        try {
            return match ($action) {
                'status' => $this->status($site, $client),
                'flush' => $this->flush($site, $client),
                default => $this->move($site, $action, $mover),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(Site $site, RedisServerClient $client): int
    {
        $this->line('SITE='.$site->slug);
        $this->line('APP_UUID='.$site->coolify_app_uuid);
        $this->line('SHARED='.($site->usesSharedRedis() ? 'yes' : 'no'));
        $this->printEnv(SiteRedisPlacement::envFor($site));

        $target = $site->redisServer;
        $this->line('TARGET_SERVER='.($target->name ?? ''));
        $this->line('TARGET_HOST='.($target->host ?? ''));
        $this->line('TARGET_PORT='.($target->port ?? ''));
        $this->line('TARGET_DB='.($site->redis_db ?? ''));
        $this->line('TARGET_CACHE_DB='.($site->redis_db !== null ? $site->redis_db + 1 : ''));
        $this->line('TARGET_USERNAME='.($site->redis_username ?: SiteRedisPlacement::namesFor($site)['username']));
        $this->line('TARGET_PASSWORD='.$this->masked((string) ($site->redis_password_encrypted ?? '')));
        $this->line('PREFIX='.SiteRedisPlacement::prefixFor($site));

        if ($this->option('keys') && $target !== null && $site->redis_db !== null) {
            $counts = $client->siteKeyCounts($site);
            $this->line('KEYS_DB='.$counts['db']);
            $this->line('KEYS_CACHE_DB='.$counts['cache_db']);
        }

        return self::SUCCESS;
    }

    private function flush(Site $site, RedisServerClient $client): int
    {
        $all = (bool) $this->option('all');
        if ($all && $site->usesSharedRedis()) {
            $this->error('The site runs on the shared Redis: --all would drop its sessions and queued jobs. Flush the cache only.');

            return self::FAILURE;
        }

        $deleted = $client->flushSite($site, $all);
        $site->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'site.redis_flushed',
            'before' => null,
            'after' => ['server' => $site->redisServer?->name, 'db' => $site->redis_db, 'scope' => $all ? 'all' : 'cache', 'deleted' => $deleted],
            'ip' => null,
        ]);

        $this->line('DELETED='.$deleted);

        return self::SUCCESS;
    }

    private function move(Site $site, string $action, SiteRedisMover $mover): int
    {
        $server = null;
        if ($action !== 'local') {
            $server = $this->resolveServer();
            if ($server === null) {
                $this->error('Redis server not found (pass --server or mark one as default).');

                return self::FAILURE;
            }
        }

        if ($action === 'prepare') {
            $result = $mover->prepare($site, $server);
            $this->line('HOST='.$server->host);
            $this->line('PORT='.$server->port);
            $this->line('DB='.$result['db']);
            $this->line('CACHE_DB='.$result['cache_db']);
            $this->line('USERNAME='.$result['username']);
            $this->line('PREFIX='.$result['prefix']);

            return self::SUCCESS;
        }

        $env = $mover->switchTo($site, $server, CoolifyApplicationService::forSite($site), restart: ! $this->option('no-restart'));
        $this->printEnv($env);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function printEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            $this->line($key.'='.($key === 'REDIS_PASSWORD' ? $this->masked($value) : $value));
        }
    }

    private function masked(string $value): string
    {
        return $value !== '' && ! $this->option('show-secret') ? '***' : $value;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);

        return Site::query()
            ->where('slug', $needle)
            ->orWhere('id', $needle)
            ->orWhere('primary_domain', strtolower($needle))
            ->orWhere('coolify_app_uuid', $needle)
            ->first();
    }

    private function resolveServer(): ?RedisServer
    {
        $option = trim((string) $this->option('server'));
        if ($option === '') {
            return RedisServer::default();
        }

        return RedisServer::query()->where('id', $option)->orWhere('name', $option)->first();
    }
}
