<?php

namespace App\Services\Databases;

use App\Models\DatabaseServer;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Moves a site between its own compose MySQL and a shared server.
 *
 * Two steps, so the data copy can happen in between (tools/ops/move-site-db.sh):
 *   prepare  → schema + user exist on the server; the site still runs on its old DB.
 *   switch   → Plane records the placement, writes DB_* env to Coolify, restarts the app.
 * Switching back to local only rewrites env: the old compose volume is never touched.
 */
class SiteDatabaseMover
{
    public function __construct(
        private readonly DatabaseServerClient $client,
    ) {}

    /**
     * @return array{database: string, username: string}
     */
    public function prepare(Site $site, DatabaseServer $server, CoolifyApplicationService $coolify): array
    {
        $this->assertPasswordMatchesCoolify($site, $coolify);

        $names = $this->client->ensureSiteDatabase($server, $site);
        $this->audit($site, 'site.database_prepared', null, ['server' => $server->name] + $names);

        return $names;
    }

    /**
     * @return array<string, string> the DB_* env written (no secrets)
     */
    public function switchTo(Site $site, ?DatabaseServer $server, CoolifyApplicationService $coolify, bool $restart = true): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new RuntimeException('Site has no Coolify application.');
        }

        $before = SiteDatabasePlacement::envFor($site);

        if ($server !== null) {
            $this->assertPasswordMatchesCoolify($site, $coolify);
            $names = SiteDatabasePlacement::namesFor($site);
            $site->forceFill([
                'database_server_id' => $server->id,
                'db_database' => $names['database'],
                'db_username' => $names['username'],
            ]);
        } else {
            $site->forceFill(['database_server_id' => null, 'db_database' => null, 'db_username' => null]);
        }

        $site->save();
        $site->unsetRelation('databaseServer');

        $env = SiteDatabasePlacement::envFor($site);
        $coolify->updateEnvs($uuid, $env);

        if ($restart) {
            $coolify->restartApplication($uuid);
        }

        $this->audit($site, 'site.database_switched', $before, $env + ['restarted' => $restart ? 'yes' : 'no']);
        Log::info('site.database_switched', ['site_id' => $site->id, 'site_slug' => $site->slug, 'db_host' => $env['DB_HOST']]);

        return $env;
    }

    /**
     * The shared user is created with Plane's copy of DB_PASSWORD; the app logs in
     * with Coolify's. They must be the same value or the switch locks the site out.
     */
    private function assertPasswordMatchesCoolify(Site $site, CoolifyApplicationService $coolify): void
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        $live = '';
        foreach ($coolify->listEnvs($uuid) as $env) {
            if ($env->key === 'DB_PASSWORD' && ! $env->isPreview) {
                $live = (string) ($env->value() ?? '');
            }
        }

        if (trim($live) === '') {
            throw new RuntimeException('Coolify has no DB_PASSWORD for this site.');
        }

        $stored = (string) ($site->db_password_encrypted ?? '');
        if ($stored === '') {
            app(CoolifyAppEnvSync::class)->captureDbSecrets($site, ['DB_PASSWORD' => $live]);

            return;
        }

        if (! hash_equals($stored, $live)) {
            throw new RuntimeException('Plane\'s DB_PASSWORD copy differs from Coolify; resolve the drift before moving the database.');
        }
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
