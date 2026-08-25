<?php

declare(strict_types=1);

namespace App\Console\Commands\SelfHost;

use App\Service\SelfHost\DatabaseBackupConfiguration;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class SelfHostDatabaseBackupCommand extends Command
{
    protected $signature = 'self-host:backup-database';

    protected $description = 'Create and verify a PostgreSQL database backup';

    public function handle(): int
    {
        $configuration = DatabaseBackupConfiguration::load();
        $destination = $configuration->destinationPath();
        $partialPath = null;
        $lock = null;

        try {
            $connection = config('database.default');
            $database = config("database.connections.{$connection}");

            if (! is_array($database) || ($database['driver'] ?? null) !== 'pgsql') {
                throw new RuntimeException('Database backups currently support PostgreSQL connections only.');
            }

            $this->prepareDestination($destination);
            $lock = fopen($destination.DIRECTORY_SEPARATOR.'.solidtime-backup.lock', 'c');

            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another Solidtime database backup is already running.');
            }

            $filename = 'solidtime-'.now()->format('Ymd-His').'.dump';
            $finalPath = $destination.DIRECTORY_SEPARATOR.$filename;
            $partialPath = $finalPath.'.partial';
            $environment = ['PGPASSWORD' => (string) ($database['password'] ?? '')];

            if (! empty($database['sslmode'])) {
                $environment['PGSSLMODE'] = (string) $database['sslmode'];
            }

            $dump = Process::env($environment)
                ->timeout($configuration->timeoutSeconds)
                ->run([
                    'pg_dump',
                    '--host='.(string) $database['host'],
                    '--port='.(string) $database['port'],
                    '--username='.(string) $database['username'],
                    '--dbname='.(string) $database['database'],
                    '--format=custom',
                    '--no-owner',
                    '--no-privileges',
                    '--file='.$partialPath,
                ]);
            $this->ensureProcessSucceeded($dump, 'pg_dump');

            if (! is_file($partialPath) || filesize($partialPath) === 0) {
                throw new RuntimeException('pg_dump completed without producing a backup file.');
            }

            $validation = Process::timeout($configuration->timeoutSeconds)
                ->run(['pg_restore', '--list', $partialPath]);
            $this->ensureProcessSucceeded($validation, 'pg_restore validation');

            if (! rename($partialPath, $finalPath)) {
                throw new RuntimeException('Could not finalize the database backup.');
            }
            $partialPath = null;

            $this->removeExpiredBackups($destination, $configuration->retentionDays);
            $this->info("Database backup created: {$finalPath} (".filesize($finalPath).' bytes)');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($partialPath !== null && is_file($partialPath)) {
                @unlink($partialPath);
            }

            report($exception);
            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function prepareDestination(string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0750, true) && ! is_dir($destination)) {
            throw new RuntimeException("Could not create backup directory: {$destination}");
        }

        if (! is_writable($destination)) {
            throw new RuntimeException("Backup directory is not writable: {$destination}");
        }
    }

    private function ensureProcessSucceeded(ProcessResult $result, string $name): void
    {
        if ($result->failed()) {
            throw new RuntimeException("{$name} failed: ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function removeExpiredBackups(string $destination, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $backups = glob($destination.DIRECTORY_SEPARATOR.'solidtime-????????-??????.dump') ?: [];

        foreach ($backups as $backup) {
            $modifiedAt = filemtime($backup);

            if ($modifiedAt !== false && $modifiedAt < $cutoff) {
                @unlink($backup);
            }
        }
    }
}
