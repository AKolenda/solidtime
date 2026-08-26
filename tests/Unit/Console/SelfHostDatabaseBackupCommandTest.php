<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\DatabaseBackupSetting;
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
        $expiredBackup = $this->backupRoot.'/solidtime-20260101-000000.sql';
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

            if (is_array($process->command) && $process->command[0] === 'pg_restore' && in_array('--no-owner', $process->command, true)) {
                $fileArgument = collect($process->command)->first(
                    fn (string $argument): bool => str_starts_with($argument, '--file=')
                );
                File::put(substr($fileArgument, strlen('--file=')), '-- readable PostgreSQL SQL backup');
            }

            return Process::result();
        })->preventStrayProcesses();

        $this->artisan('self-host:backup-database')
            ->expectsOutputToContain('Database backup created:')
            ->assertSuccessful();

        $finalPath = $this->backupRoot.'/solidtime-20260825-123456.sql';
        $this->assertFileExists($finalPath);
        $this->assertFileDoesNotExist($finalPath.'.partial');
        $this->assertFileDoesNotExist($expiredBackup);
        $this->assertFileExists($unrelatedFile);
        $this->assertDatabaseHas('database_backup_runs', [
            'status' => 'completed',
            'filename' => 'solidtime-20260825-123456.sql',
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
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'pg_restore'
            && in_array('--no-owner', $process->command, true));
        Process::assertRanTimes(fn (): bool => true, 3);
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

        $this->assertSame([], glob($this->backupRoot.'/*.sql*'));
        $this->assertSame([], glob($this->backupRoot.'/*.archive.partial'));
        $this->assertDatabaseHas('database_backup_runs', [
            'status' => 'failed',
            'validated' => false,
        ]);
    }

    public function test_it_can_keep_both_archive_and_sql_formats(): void
    {
        DatabaseBackupSetting::query()->create([
            'enabled' => true,
            'root_path' => $this->backupRoot,
            'output_format' => 'both',
            'time' => '02:00',
            'timezone' => 'UTC',
            'retention_days' => 30,
            'subdirectory' => '',
        ]);

        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && $process->command[0] === 'pg_dump') {
                $fileArgument = collect($process->command)->first(
                    fn (string $argument): bool => str_starts_with($argument, '--file=')
                );
                File::put(substr($fileArgument, strlen('--file=')), 'valid custom-format dump');
            }
            if (is_array($process->command) && $process->command[0] === 'pg_restore' && in_array('--file=', array_map(fn (string $argument): string => str_starts_with($argument, '--file=') ? '--file=' : $argument, $process->command), true)) {
                $fileArgument = collect($process->command)->first(
                    fn (string $argument): bool => str_starts_with($argument, '--file=')
                );
                File::put(substr($fileArgument, strlen('--file=')), '-- PostgreSQL database dump');
            }

            return Process::result();
        })->preventStrayProcesses();

        $this->artisan('self-host:backup-database')->assertSuccessful();

        $this->assertFileExists($this->backupRoot.'/solidtime-20260825-123456.dump');
        $this->assertFileExists($this->backupRoot.'/solidtime-20260825-123456.sql');
        $this->assertDatabaseHas('database_backup_runs', [
            'filename' => 'solidtime-20260825-123456.dump, solidtime-20260825-123456.sql',
            'validated' => true,
        ]);
    }
}
