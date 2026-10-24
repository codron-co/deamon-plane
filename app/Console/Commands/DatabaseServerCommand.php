<?php

namespace App\Console\Commands;

use App\Models\DatabaseServer;
use App\Services\Databases\DatabaseServerClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Register (or update) a shared MySQL server and probe it. The admin password is
 * read from STDIN so it never lands in shell history or the process list:
 *   printf '%s' "$PW" | php artisan ops:db-server "Deamon MySQL" deamon-db-mysql --user=plane_admin --default
 */
class DatabaseServerCommand extends Command
{
    protected $signature = 'ops:db-server
        {name : display name}
        {host : hostname on the deamon-db network}
        {--port=3306}
        {--user=plane_admin : admin user}
        {--default : new sites and `ops:site-db` use this server}';

    protected $description = 'Register a shared MySQL server (admin password on STDIN) and probe it';

    public function handle(DatabaseServerClient $client): int
    {
        $password = trim((string) stream_get_contents(STDIN));
        if ($password === '') {
            $this->error('Admin password expected on STDIN.');

            return self::FAILURE;
        }

        $server = DatabaseServer::query()->firstOrNew(['name' => (string) $this->argument('name')]);
        $server->fill([
            'host' => (string) $this->argument('host'),
            'port' => (int) $this->option('port'),
            'admin_username' => (string) $this->option('user'),
            'admin_password' => $password,
        ]);
        if ($this->option('default')) {
            DatabaseServer::query()->whereKeyNot($server->getKey() ?? '')->update(['is_default' => false]);
            $server->is_default = true;
        }
        $server->save();

        try {
            $client->refresh($server);
        } catch (Throwable $e) {
            $this->error('Saved, but the probe failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $payload = $server->last_probe_payload ?? [];
        $this->info(sprintf('%s: MySQL %s, %d schema(s).', $server->name, $payload['version'] ?? '?', count($payload['databases'] ?? [])));

        return self::SUCCESS;
    }
}
