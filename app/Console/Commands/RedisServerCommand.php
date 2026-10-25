<?php

namespace App\Console\Commands;

use App\Models\RedisServer;
use App\Services\Databases\RedisServerClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Register (or update) a shared Redis server and probe it. The admin password is
 * read from STDIN so it never lands in shell history or the process list:
 *   printf '%s' "$PW" | php artisan ops:redis-server "Deamon Redis" deamon-redis --user=plane_admin --default
 */
class RedisServerCommand extends Command
{
    protected $signature = 'ops:redis-server
        {name : display name}
        {host : hostname on the deamon-db network (deamon-redis, never redis)}
        {--port=6379}
        {--user=plane_admin : admin ACL user}
        {--default : `ops:site-redis` uses this server unless --server is given}';

    protected $description = 'Register a shared Redis server (admin password on STDIN) and probe it';

    public function handle(RedisServerClient $client): int
    {
        $password = trim((string) stream_get_contents(STDIN));
        if ($password === '') {
            $this->error('Admin password expected on STDIN.');

            return self::FAILURE;
        }

        $server = RedisServer::query()->firstOrNew(['name' => (string) $this->argument('name')]);
        $server->fill([
            'host' => (string) $this->argument('host'),
            'port' => (int) $this->option('port'),
            'admin_username' => (string) $this->option('user'),
            'admin_password' => $password,
        ]);
        if ($this->option('default')) {
            RedisServer::query()->whereKeyNot($server->getKey() ?? '')->update(['is_default' => false]);
            $server->is_default = true;
        }
        $server->save();

        try {
            $client->refresh($server);
        } catch (Throwable $e) {
            $this->error('Saved, but the probe failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $payload = $server->probe();
        $this->info(sprintf(
            '%s: Redis %s, %s used of %s, %d client(s).',
            $server->name,
            $payload['version'] ?? '?',
            self::mb($payload['used_memory'] ?? 0),
            ($payload['maxmemory'] ?? 0) > 0 ? self::mb($payload['maxmemory']) : 'no limit',
            (int) ($payload['connected_clients'] ?? 0),
        ));

        return self::SUCCESS;
    }

    private static function mb(mixed $bytes): string
    {
        return number_format(((int) $bytes) / 1048576, 1).' MB';
    }
}
