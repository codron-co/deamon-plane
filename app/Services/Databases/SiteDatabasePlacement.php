<?php

namespace App\Services\Databases;

use App\Models\DatabaseServer;
use App\Models\Site;

/**
 * Where a site's database lives and the DB_* env the CMS compose reads.
 *
 * The CMS compose defaults (`mysql` / `deamon` / `deamon`) are the site's own
 * stack; a site on a shared server gets its own schema and user there. The
 * password is always the site's existing DB_PASSWORD, so moving a site never
 * changes a secret.
 */
final class SiteDatabasePlacement
{
    public const LOCAL_HOST = 'mysql';

    public const LOCAL_PORT = 3306;

    public const LOCAL_DATABASE = 'deamon';

    public const LOCAL_USERNAME = 'deamon';

    /**
     * Env keys Plane writes for the placement. They are bootstrap keys: never pruned.
     *
     * @var list<string>
     */
    public const ENV_KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'];

    /** MySQL 8 user names are limited to 32 characters. */
    private const USERNAME_MAX = 32;

    /**
     * Schema and user name a site gets on a shared server, from its slug.
     *
     * @return array{database: string, username: string}
     */
    public static function namesFor(Site $site): array
    {
        $base = 'site_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $site->slug)), '_');
        $database = substr($base, 0, 64);

        $username = strlen($base) <= self::USERNAME_MAX
            ? $base
            : substr($base, 0, self::USERNAME_MAX - 7).'_'.substr(hash('sha256', (string) $site->slug), 0, 6);

        return ['database' => $database, 'username' => $username];
    }

    /**
     * @return array{DB_HOST: string, DB_PORT: string, DB_DATABASE: string, DB_USERNAME: string}
     */
    public static function envFor(Site $site): array
    {
        $server = $site->usesSharedDatabase() ? $site->databaseServer : null;

        if (! $server instanceof DatabaseServer) {
            return [
                'DB_HOST' => self::LOCAL_HOST,
                'DB_PORT' => (string) self::LOCAL_PORT,
                'DB_DATABASE' => self::LOCAL_DATABASE,
                'DB_USERNAME' => self::LOCAL_USERNAME,
            ];
        }

        return [
            'DB_HOST' => (string) $server->host,
            'DB_PORT' => (string) $server->port,
            'DB_DATABASE' => (string) $site->db_database,
            'DB_USERNAME' => (string) $site->db_username,
        ];
    }

    public static function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $name) === 1;
    }
}
