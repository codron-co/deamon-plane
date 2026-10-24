<?php

namespace App\Console\Commands;

use App\Models\DatabaseServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Databases\SiteDatabaseMover;
use App\Services\Databases\SiteDatabasePlacement;
use Illuminate\Console\Command;
use Throwable;

/**
 * Database placement of one site, for tools/ops/move-site-db.sh and operators.
 *
 *   status   where the site's database is and the DB_* env it should have
 *   prepare  create the schema + user on the shared server (site keeps running)
 *   switch   point the site at the shared server and restart it
 *   local    point the site back at its own compose MySQL and restart it
 *
 * Output lines are KEY=value so the shell script can read them; no secrets.
 */
class SiteDatabaseCommand extends Command
{
    protected $signature = 'ops:site-db
        {action : status|prepare|switch|local}
        {site : site slug, id, primary domain or Coolify app uuid}
        {--server= : database server name or id (default: the default server)}
        {--no-restart : write env only; restart later}';

    protected $description = 'Prepare, switch or revert a site\'s database placement (shared MySQL)';

    public function handle(SiteDatabaseMover $mover): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'prepare', 'switch', 'local'], true)) {
            $this->error('Unknown action: '.$action);

            return self::INVALID;
        }

        if ($action === 'status') {
            $this->line('SITE='.$site->slug);
            $this->line('APP_UUID='.$site->coolify_app_uuid);
            $this->line('SHARED='.($site->usesSharedDatabase() ? 'yes' : 'no'));
            foreach (SiteDatabasePlacement::envFor($site) as $key => $value) {
                $this->line($key.'='.$value);
            }
            $names = SiteDatabasePlacement::namesFor($site);
            $this->line('TARGET_DATABASE='.$names['database']);
            $this->line('TARGET_USERNAME='.$names['username']);

            return self::SUCCESS;
        }

        $server = null;
        if ($action !== 'local') {
            $server = $this->resolveServer();
            if ($server === null) {
                $this->error('Database server not found (pass --server or mark one as default).');

                return self::FAILURE;
            }
        }

        $coolify = CoolifyApplicationService::forSite($site);

        try {
            if ($action === 'prepare') {
                $names = $mover->prepare($site, $server, $coolify);
                $this->line('DATABASE='.$names['database']);
                $this->line('USERNAME='.$names['username']);
                $this->line('HOST='.$server->host);

                return self::SUCCESS;
            }

            $env = $mover->switchTo($site, $server, $coolify, restart: ! $this->option('no-restart'));
            foreach ($env as $key => $value) {
                $this->line($key.'='.$value);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
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

    private function resolveServer(): ?DatabaseServer
    {
        $option = trim((string) $this->option('server'));
        if ($option === '') {
            return DatabaseServer::default();
        }

        return DatabaseServer::query()->where('id', $option)->orWhere('name', $option)->first();
    }
}
