<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SelfHostDatabaseBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = storage_path('framework/testing/database-backup-'.uniqid());
        File::makeDirectory($this->backupRoot, 0750, true);
        Carbon::setTestNow('2026-08-25 12:34:56');

        config([
            'database-backup.connection' => 'pgsql',
            'database.connections.pgsql.host' => 'pgsql',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.database' => 'solidtime',
            'database.connections.pgsql.username' => 'solidtime-user',
            'database.connections.pgsql.password' => 'secret-password',
            'database-backup.root_path' => $this->backupRoot,
            'database-backup.retention_days' => 30,
            'database-backup.timeout_seconds' => 120,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->backupRoot);

        parent::tearDown();
    }

    public function test_it_creates_validates_and_rotates_a_database_backup(): void
    {
        $expiredBackup = $this->backupRoot.'/solidtime-20260101-000000.dump';
        $unrelatedFile = $this->backupRoot.'/keep-me.txt';
        File::put($expiredBackup, 'expired');
        File::put($unrelatedFile, 'unrelated');
        touch($expiredBackup, Carbon::now()->subDays(31)->getTimestamp());
        touch($unrelatedFile, Carbon::now()->subDays(31)->getTimestamp());

        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && $process->command[0] === 'pg_dump') {
                $fileArgument = collect($process->command)->first(
                    fn (string $argument): bool => str_starts_with($argument, '--file=')
                );
                File::put(substr($fileArgument, strlen('--file=')), 'valid custom-format dump');
            }

            return Process::result();
        })->preventStrayProcesses();

        $this->artisan('self-host:backup-database')
            ->expectsOutputToContain('Database backup created:')
            ->assertSuccessful();

        $finalPath = $this->backupRoot.'/solidtime-20260825-123456.dump';
        $this->assertFileExists($finalPath);
        $this->assertFileDoesNotExist($finalPath.'.partial');
        $this->assertFileDoesNotExist($expiredBackup);
        $this->assertFileExists($unrelatedFile);
        $this->assertDatabaseHas('database_backup_runs', [
            'status' => 'completed',
            'filename' => 'solidtime-20260825-123456.dump',
            'validated' => true,
        ]);

        Process::assertRan(function (PendingProcess $process): bool {
            return is_array($process->command)
                && $process->command[0] === 'pg_dump'
                && $process->environment['PGPASSWORD'] === 'secret-password'
                && ! in_array('secret-password', $process->command, true)
                && $process->timeout === 120;
        });
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'pg_restore'
            && $process->command[1] === '--list');
        Process::assertRanTimes(fn (): bool => true, 2);
    }

    public function test_it_removes_a_partial_file_when_validation_fails(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && $process->command[0] === 'pg_dump') {
                $fileArgument = collect($process->command)->first(
                    fn (string $argument): bool => str_starts_with($argument, '--file=')
                );
                File::put(substr($fileArgument, strlen('--file=')), 'invalid dump');

                return Process::result();
            }

            return Process::result(errorOutput: 'archive is invalid', exitCode: 1);
        })->preventStrayProcesses();

        $this->artisan('self-host:backup-database')
            ->expectsOutputToContain('Database backup failed: pg_restore validation failed')
            ->assertFailed();

        $this->assertSame([], glob($this->backupRoot.'/*.dump*'));
        $this->assertDatabaseHas('database_backup_runs', [
            'status' => 'failed',
            'validated' => false,
        ]);
    }
}
