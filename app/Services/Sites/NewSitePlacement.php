<?php

namespace App\Services\Sites;

use App\Enums\DeploymentStatus;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Databases\SiteDatabaseMover;
use App\Services\Databases\SiteRedisMover;
use Illuminate\Support\Str;

/**
 * Puts a site being provisioned on the shared MySQL and Redis before its first build.
 *
 * The CMS compose is losing its own `mysql` / `redis` services, so a new site left on
 * the compose defaults (DB_HOST=mysql, REDIS_HOST=redis) would not boot. The steps are
 * the ones ops:site-db and ops:site-redis run (prepare, then switch without a restart);
 * a new site has no data to copy. Each step is idempotent, so a re-provision repeats them.
 *
 * A site that already ran on its own MySQL or Redis is never moved from here: its data
 * has to be copied first (tools/ops/move-site-db.sh, move-site-redis.sh).
 */
class NewSitePlacement
{
    public function __construct(
        private readonly SiteDatabaseMover $databases,
        private readonly SiteRedisMover $redis,
    ) {}

    /**
     * The servers the site goes to: the one it already uses (a re-provision), else the default.
     *
     * @return array{database: DatabaseServer, redis: RedisServer}
     *
     * @throws SiteProvisionException
     */
    public function targets(Site $site): array
    {
        $database = $site->usesSharedDatabase() ? $site->databaseServer : null;
        if (! $database instanceof DatabaseServer) {
            $this->assertHasNotRun($site);
            $database = DatabaseServer::default();
        }
        if (! $database instanceof DatabaseServer) {
            throw new SiteProvisionException(__('sites.provision_errors.no_default_database_server'));
        }

        // A pair reserved by an earlier attempt (or a move back) is reused, not doubled.
        $redis = $site->usesSharedRedis() ? $site->redisServer : null;
        if (! $redis instanceof RedisServer) {
            $this->assertHasNotRun($site);
            $redis = $site->redisServer ?? RedisServer::default();
        }
        if (! $redis instanceof RedisServer) {
            throw new SiteProvisionException(__('sites.provision_errors.no_default_redis_server'));
        }

        return ['database' => $database, 'redis' => $redis];
    }

    /**
     * Prepare both servers and write DB_* / REDIS_* to Coolify. No restart: the first
     * deploy that follows boots the site on them.
     *
     * @return array{database_server: string, database: string, redis_server: string, redis_db: int, redis_prefix: string}
     *
     * @throws SiteProvisionException
     */
    public function place(Site $site, CoolifyApplicationService $coolify): array
    {
        ['database' => $database, 'redis' => $redis] = $this->targets($site);

        $this->ensureDbPassword($site, $coolify);
        $names = $this->databases->prepare($site, $database, $coolify);
        $this->databases->switchTo($site, $database, $coolify, restart: false);

        $reserved = $this->redis->prepare($site, $redis);
        $this->redis->switchTo($site, $redis, $coolify, restart: false);

        return [
            'database_server' => (string) $database->name,
            'database' => $names['database'],
            'redis_server' => (string) $redis->name,
            'redis_db' => $reserved['db'],
            'redis_prefix' => $reserved['prefix'],
        ];
    }

    /**
     * The shared MySQL user is created with Plane's copy of DB_PASSWORD and the app logs
     * in with Coolify's, so both must hold the same value before prepare. A fresh app has
     * neither yet (the deploy env sync would generate one only at build time): Plane keeps
     * its copy, or makes one, and writes it. A live value is left to prepare, which stores
     * it or reports drift.
     */
    private function ensureDbPassword(Site $site, CoolifyApplicationService $coolify): void
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new SiteProvisionException('Site has no Coolify application.');
        }

        $live = '';
        foreach ($coolify->listEnvs($uuid) as $env) {
            if ($env->key === 'DB_PASSWORD' && ! $env->isPreview) {
                $live = (string) ($env->value() ?? '');
            }
        }

        if (! CoolifyAppEnvSync::isPlaceholder($live)) {
            return;
        }

        $password = (string) ($site->db_password_encrypted ?? '');
        if (CoolifyAppEnvSync::isPlaceholder($password)) {
            $password = Str::password(40, symbols: false);
            $site->forceFill(['db_password_encrypted' => $password])->save();
        }

        $coolify->updateEnvs($uuid, ['DB_PASSWORD' => $password]);
    }

    /**
     * A finished build means the site has booted on its own MySQL/Redis and may hold data
     * there; switching it to an empty schema would hide that data.
     */
    private function assertHasNotRun(Site $site): void
    {
        if ($site->deployments()->where('status', DeploymentStatus::Finished)->exists()) {
            throw new SiteProvisionException(__('sites.provision_errors.ran_on_local_services'));
        }
    }
}
