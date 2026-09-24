<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\BackupPlaneDatabaseCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupPlaneDatabaseCommandTest extends TestCase
{
    private const PASSWORD = 'plane-db-password-never-in-argv';

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plane-backup-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage);
        $this->app->useStoragePath($this->storage);

        config([
            'database.default' => 'backup_mysql',
            'database.connections.backup_mysql' => [
                'driver' => 'mysql',
                'host' => 'mysql',
                'port' => '3306',
                'database' => 'plane',
                'username' => 'plane',
                'password' => self::PASSWORD,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_dump_is_compressed_and_the_password_never_reaches_argv(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents($this->resultFile($process), "-- plane dump\nCREATE TABLE sites (id int);\n");

            return Process::result();
        });

        $this->artisan('ops:backup-db')->assertSuccessful();

        $dumps = File::glob(BackupPlaneDatabaseCommand::directory().DIRECTORY_SEPARATOR.'plane-db-*');
        $this->assertCount(1, $dumps);
        $this->assertStringEndsWith('.sql.gz', $dumps[0]);
        $this->assertStringContainsString('CREATE TABLE sites', (string) gzdecode((string) file_get_contents($dumps[0])));

        Process::assertRan(function (PendingProcess $process): bool {
            $argv = implode(' ', (array) $process->command);

            return str_starts_with($argv, 'mysqldump ')
                && str_contains($argv, '--single-transaction')
                && ! str_contains($argv, self::PASSWORD)
                && ($process->environment['MYSQL_PWD'] ?? null) === self::PASSWORD;
        });
    }

    public function test_failed_dump_exits_non_zero_and_leaves_no_file(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied', exitCode: 2));

        $this->artisan('ops:backup-db')->assertFailed();

        $this->assertSame([], File::glob(BackupPlaneDatabaseCommand::directory().DIRECTORY_SEPARATOR.'plane-db-*'));
    }

    public function test_old_dumps_are_pruned_but_the_newest_three_always_stay(): void
    {
        $directory = BackupPlaneDatabaseCommand::directory();
        File::ensureDirectoryExists($directory);
        $old = now()->subDays(BackupPlaneDatabaseCommand::RETENTION_DAYS + 5)->getTimestamp();
        foreach (range(1, 4) as $day) {
            $path = $directory.DIRECTORY_SEPARATOR.sprintf('plane-db-2026090%d-033000.sql.gz', $day);
            file_put_contents($path, 'old');
            touch($path, $old + $day);
        }

        Process::fake(function (PendingProcess $process) {
            file_put_contents($this->resultFile($process), '-- new');

            return Process::result();
        });

        $this->artisan('ops:backup-db')->assertSuccessful();

        $left = collect(File::glob($directory.DIRECTORY_SEPARATOR.'plane-db-*.sql.gz'))->map(fn ($p) => basename($p));
        $this->assertCount(3, $left);
        $this->assertNotContains('plane-db-20260901-033000.sql.gz', $left);
        $this->assertNotContains('plane-db-20260902-033000.sql.gz', $left);
        $this->assertContains('plane-db-20260904-033000.sql.gz', $left);
    }

    public function test_refuses_a_non_mysql_connection(): void
    {
        config(['database.connections.backup_mysql.driver' => 'sqlite']);
        Process::fake();

        $this->artisan('ops:backup-db')->assertFailed();

        Process::assertNothingRan();
    }

    private function resultFile(PendingProcess $process): string
    {
        foreach ((array) $process->command as $argument) {
            if (str_starts_with((string) $argument, '--result-file=')) {
                return substr((string) $argument, strlen('--result-file='));
            }
        }

        $this->fail('mysqldump was not given --result-file');
    }
}
