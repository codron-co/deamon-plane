<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Nightly logical dump of Plane's own MySQL. Plane holds every connection
 * credential and site agent secret in that database, so losing it without a
 * copy means rebuilding the fleet by hand. Dumps land on their own volume
 * (storage/app/backups) and older ones are pruned. Restore: runbook
 * docs/runbooks/plane-db-backup.md.
 */
class BackupPlaneDatabaseCommand extends Command
{
    public const RETENTION_DAYS = 14;

    public const FILE_PREFIX = 'plane-db-';

    protected $signature = 'ops:backup-db';

    protected $description = 'Dump Plane\'s MySQL database to storage/app/backups and prune old dumps';

    public function handle(): int
    {
        $name = (string) config('database.default');
        $connection = (array) config('database.connections.'.$name, []);

        if (($connection['driver'] ?? null) !== 'mysql') {
            $this->error('ops:backup-db only dumps a MySQL connection; the default connection is '.($connection['driver'] ?? 'unknown').'.');

            return self::FAILURE;
        }

        $directory = self::directory();
        File::ensureDirectoryExists($directory);

        $stamp = Carbon::now()->format('Ymd-His');
        $sqlPath = $directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.$stamp.'.sql';
        $gzPath = $sqlPath.'.gz';

        $result = Process::timeout(900)
            ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->run([
                'mysqldump',
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? '3306'),
                '--user='.($connection['username'] ?? ''),
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$sqlPath,
                (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
            File::delete([$sqlPath, $gzPath]);
            Log::error('ops.backup_db_failed', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr(trim($result->errorOutput()), 0, 500),
            ]);
            $this->error('Plane database dump failed: '.trim($result->errorOutput()));

            return self::FAILURE;
        }

        if (! $this->gzip($sqlPath, $gzPath)) {
            File::delete([$sqlPath, $gzPath]);
            Log::error('ops.backup_db_failed', ['error' => 'gzip']);
            $this->error('Plane database dump could not be compressed.');

            return self::FAILURE;
        }

        File::delete($sqlPath);
        $pruned = $this->prune($directory);

        Log::info('ops.backup_db_created', [
            'file' => basename($gzPath),
            'bytes' => filesize($gzPath),
            'pruned' => $pruned,
        ]);
        $this->info(sprintf('Plane database dumped to %s (%d bytes); %d old dump(s) pruned.', basename($gzPath), (int) filesize($gzPath), $pruned));

        return self::SUCCESS;
    }

    public static function directory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'backups');
    }

    private function gzip(string $source, string $target): bool
    {
        $partial = $target.'.partial';
        $in = fopen($source, 'rb');
        $out = gzopen($partial, 'wb6');
        if ($in === false || $out === false) {
            return false;
        }

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || gzwrite($out, $chunk) === false) {
                fclose($in);
                gzclose($out);
                File::delete($partial);

                return false;
            }
        }

        fclose($in);
        gzclose($out);

        return rename($partial, $target);
    }

    private function prune(string $directory): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS)->getTimestamp();
        $dumps = collect(File::glob($directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.'*.sql.gz'))
            ->sortByDesc(static fn (string $path): int => (int) filemtime($path))
            ->values();

        $pruned = 0;
        foreach ($dumps as $index => $path) {
            // Always keep the newest few, even if the clock or the schedule was off.
            if ($index < 3 || (int) filemtime($path) >= $cutoff) {
                continue;
            }

            if (File::delete($path)) {
                $pruned++;
            }
        }

        return $pruned;
    }
}
