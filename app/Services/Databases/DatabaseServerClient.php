<?php

namespace App\Services\Databases;

use App\Models\DatabaseServer;
use App\Models\Site;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Talks to a shared MySQL server.
 *
 * Server-level work (create a site schema + user, sizes) uses the admin login.
 * Everything that touches site data runs as that site's own user, so a console
 * query can never reach another site's schema.
 */
class DatabaseServerClient
{
    public const MAX_ROWS = 500;

    public const QUERY_TIMEOUT_MS = 15000;

    /** Schemas that belong to MySQL itself, not to a site. */
    private const SYSTEM_SCHEMAS = ['mysql', 'sys', 'performance_schema', 'information_schema'];

    /**
     * @return array{version: string, uptime_seconds: int, connections: int, databases: array<string, array{size_mb: float, tables: int}>}
     */
    public function probe(DatabaseServer $server): array
    {
        return $this->withConnection($this->adminConfig($server), function (Connection $db): array {
            $version = (string) $db->selectOne('SELECT VERSION() AS v')->v;
            $status = collect($db->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected')"))
                ->mapWithKeys(fn (object $row): array => [$row->Variable_name => (int) $row->Value]);

            $placeholders = implode(',', array_fill(0, count(self::SYSTEM_SCHEMAS), '?'));
            $rows = $db->select(
                "SELECT s.schema_name AS name,
                        COALESCE(ROUND(SUM(t.data_length + t.index_length) / 1048576, 1), 0) AS size_mb,
                        COUNT(t.table_name) AS tables
                   FROM information_schema.schemata s
              LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name
                  WHERE s.schema_name NOT IN ($placeholders)
               GROUP BY s.schema_name
               ORDER BY s.schema_name",
                self::SYSTEM_SCHEMAS,
            );

            $databases = [];
            foreach ($rows as $row) {
                $databases[(string) $row->name] = ['size_mb' => (float) $row->size_mb, 'tables' => (int) $row->tables];
            }

            return [
                'version' => $version,
                'uptime_seconds' => (int) ($status['Uptime'] ?? 0),
                'connections' => (int) ($status['Threads_connected'] ?? 0),
                'databases' => $databases,
            ];
        });
    }

    /**
     * Probe and store the result on the server row.
     */
    public function refresh(DatabaseServer $server): DatabaseServer
    {
        $server->forceFill([
            'last_probe_at' => now(),
            'last_probe_payload' => $this->probe($server),
        ])->save();

        return $server;
    }

    /**
     * The server's slow query log (mysql.slow_log, filled when log_output includes TABLE),
     * newest first, optionally for one schema. SQL text is cut to 2,000 characters; it can
     * hold site data, so callers show it only to users who may open site databases.
     *
     * @return array{enabled: bool, threshold_seconds: float, to_table: bool, rows: list<array{at: string, database: string, user: string, seconds: float, lock_seconds: float, rows_sent: int, rows_examined: int, sql: string}>}
     */
    public function slowQueries(DatabaseServer $server, ?string $schema = null, int $limit = 100): array
    {
        $limit = max(1, min($limit, self::MAX_ROWS));

        return $this->withConnection($this->adminConfig($server), function (Connection $db) use ($schema, $limit): array {
            $settings = $db->selectOne('SELECT @@GLOBAL.slow_query_log AS enabled, @@GLOBAL.long_query_time AS threshold, @@GLOBAL.log_output AS output');
            $toTable = str_contains(strtoupper((string) $settings->output), 'TABLE');

            $rows = ! $toTable ? [] : $db->select(
                'SELECT start_time AS at, db AS database_name, user_host,
                        TIME_TO_SEC(query_time) + MICROSECOND(query_time) / 1000000 AS seconds,
                        TIME_TO_SEC(lock_time) + MICROSECOND(lock_time) / 1000000 AS lock_seconds,
                        rows_sent, rows_examined, LEFT(CONVERT(sql_text USING utf8mb4), 2000) AS sql_text
                   FROM mysql.slow_log'.($schema !== null ? ' WHERE db = ?' : '').'
               ORDER BY start_time DESC
                  LIMIT '.$limit,
                $schema !== null ? [$schema] : [],
            );

            return [
                'enabled' => (bool) $settings->enabled,
                'threshold_seconds' => (float) $settings->threshold,
                'to_table' => $toTable,
                'rows' => array_map(fn (object $row): array => [
                    'at' => (string) $row->at,
                    'database' => (string) $row->database_name,
                    // user_host looks like "site_izyem[site_izyem] @  [172.18.0.5]"
                    'user' => trim(strstr((string) $row->user_host, '[', true) ?: (string) $row->user_host),
                    'seconds' => round((float) $row->seconds, 3),
                    'lock_seconds' => round((float) $row->lock_seconds, 3),
                    'rows_sent' => (int) $row->rows_sent,
                    'rows_examined' => (int) $row->rows_examined,
                    'sql' => (string) $row->sql_text,
                ], $rows),
            ];
        });
    }

    /**
     * Create (or align) the site's schema and user; the password is the site's DB_PASSWORD.
     * Idempotent: re-running only re-applies the password and grants.
     *
     * @return array{database: string, username: string}
     */
    public function ensureSiteDatabase(DatabaseServer $server, Site $site): array
    {
        $password = (string) ($site->db_password_encrypted ?? '');
        if ($password === '') {
            throw new RuntimeException('Site has no stored DB_PASSWORD; run ops:capture-db-secrets first.');
        }

        $names = SiteDatabasePlacement::namesFor($site);
        foreach ($names as $name) {
            if (! SiteDatabasePlacement::isSafeIdentifier($name)) {
                throw new RuntimeException("Unsafe database identifier: {$name}");
            }
        }

        $this->withConnection($this->adminConfig($server), secrets: [$password], callback: function (Connection $db) use ($names, $password): void {
            $pdo = $db->getPdo();
            $user = $pdo->quote($names['username']).'@\'%\'';
            $secret = $pdo->quote($password);

            $db->statement("CREATE DATABASE IF NOT EXISTS `{$names['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->statement("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$secret}");
            $db->statement("ALTER USER {$user} IDENTIFIED BY {$secret}");
            $db->statement("GRANT ALL PRIVILEGES ON `{$names['database']}`.* TO {$user}");
        });

        return $names;
    }

    /**
     * @return list<array{name: string, rows: int, size_mb: float, engine: string|null}>
     */
    public function tables(Site $site): array
    {
        return $this->withConnection($this->siteConfig($site), function (Connection $db): array {
            $rows = $db->select(
                'SELECT table_name AS name, table_rows AS row_estimate, engine AS engine,
                        ROUND((data_length + index_length) / 1048576, 2) AS size_mb
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
               ORDER BY (data_length + index_length) DESC, table_name'
            );

            return array_map(fn (object $row): array => [
                'name' => (string) $row->name,
                'rows' => (int) $row->row_estimate,
                'size_mb' => (float) $row->size_mb,
                'engine' => $row->engine !== null ? (string) $row->engine : null,
            ], $rows);
        });
    }

    /**
     * Run one console statement as the site's user. Reads run READ ONLY with a time limit.
     *
     * @throws SqlStatementRejected
     * @throws DatabaseServerException
     */
    public function run(Site $site, string $sql, bool $allowWrite): DatabaseQueryResult
    {
        $kind = SqlStatementGuard::classify($sql);
        if ($kind === SqlStatementGuard::WRITE && ! $allowWrite) {
            throw new SqlStatementRejected('write_not_allowed');
        }

        $statement = rtrim(trim($sql), "; \t\r\n");
        $started = microtime(true);

        return $this->withConnection($this->siteConfig($site), function (Connection $db) use ($kind, $statement, $started): DatabaseQueryResult {
            $pdo = $db->getPdo();

            if ($kind === SqlStatementGuard::WRITE) {
                $affected = $pdo->exec($statement);

                return new DatabaseQueryResult(
                    kind: $kind,
                    columns: [],
                    rows: [],
                    truncated: false,
                    affected: $affected === false ? 0 : (int) $affected,
                    milliseconds: (int) round((microtime(true) - $started) * 1000),
                );
            }

            $pdo->exec('SET SESSION MAX_EXECUTION_TIME = '.self::QUERY_TIMEOUT_MS);
            $pdo->exec('START TRANSACTION READ ONLY');

            try {
                $result = $pdo->query($statement, PDO::FETCH_ASSOC);
                $columns = [];
                $rows = [];
                $truncated = false;

                if ($result !== false) {
                    for ($i = 0; $i < $result->columnCount(); $i++) {
                        $meta = $result->getColumnMeta($i);
                        $columns[] = is_array($meta) ? (string) $meta['name'] : (string) $i;
                    }

                    while (($row = $result->fetch(PDO::FETCH_NUM)) !== false) {
                        if (count($rows) >= self::MAX_ROWS) {
                            $truncated = true;
                            break;
                        }
                        $rows[] = array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
                    }
                    $result->closeCursor();
                }
            } finally {
                $pdo->exec('ROLLBACK');
            }

            return new DatabaseQueryResult(
                kind: $kind,
                columns: $columns,
                rows: $rows,
                truncated: $truncated,
                affected: null,
                milliseconds: (int) round((microtime(true) - $started) * 1000),
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function adminConfig(DatabaseServer $server): array
    {
        return $this->baseConfig($server) + [
            'database' => 'information_schema',
            'username' => (string) $server->admin_username,
            'password' => (string) $server->admin_password,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteConfig(Site $site): array
    {
        $server = $site->usesSharedDatabase() ? $site->databaseServer : null;
        if (! $server instanceof DatabaseServer) {
            throw new RuntimeException('Site does not use a shared database server.');
        }

        return $this->baseConfig($server) + [
            'database' => (string) $site->db_database,
            'username' => (string) $site->db_username,
            'password' => (string) ($site->db_password_encrypted ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(DatabaseServer $server): array
    {
        return [
            'driver' => 'mysql',
            'host' => (string) $server->host,
            'port' => (int) $server->port,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $config
     * @param  callable(Connection): T  $callback
     * @param  list<string>  $secrets  extra values to keep out of error messages
     * @return T
     */
    protected function withConnection(array $config, callable $callback, array $secrets = []): mixed
    {
        $name = 'dbserver_'.bin2hex(random_bytes(6));
        Config::set("database.connections.{$name}", $config);

        try {
            return $callback(DB::connection($name));
        } catch (SqlStatementRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DatabaseServerException($e->getMessage(), [(string) ($config['password'] ?? ''), ...$secrets]);
        } finally {
            DB::purge($name);
            Config::set("database.connections.{$name}", null);
        }
    }
}
