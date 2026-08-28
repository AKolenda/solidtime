<?php

declare(strict_types=1);

namespace App\Service\SelfHost;

use App\Models\DatabaseBackupSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class DatabaseBackupConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $time,
        public string $timezone,
        public int $retentionDays,
        public string $subdirectory,
        public string $rootPath,
        public string $outputFormat,
        public int $timeoutSeconds,
    ) {}

    public static function load(): self
    {
        $stored = null;

        try {
            if (Schema::hasTable('database_backup_settings')) {
                $stored = DatabaseBackupSetting::query()->first();
            }
        } catch (Throwable) {
            // During installation or recovery the database may not be available yet.
        }

        $storedRootPath = $stored?->root_path;
        $rootPath = self::containerPathFor(
            $storedRootPath ?? (string) config('database-backup.root_path')
        );

        if ($stored !== null && is_string($storedRootPath) && $storedRootPath !== $rootPath) {
            $stored->forceFill(['root_path' => $rootPath])->saveQuietly();
        }

        return new self(
            enabled: $stored?->enabled ?? (bool) config('database-backup.enabled'),
            time: $stored?->time ?? (string) config('database-backup.time'),
            timezone: $stored?->timezone ?? (string) config('database-backup.timezone'),
            retentionDays: $stored?->retention_days ?? (int) config('database-backup.retention_days'),
            subdirectory: $stored?->subdirectory ?? '',
            rootPath: $rootPath,
            outputFormat: $stored?->output_format ?? 'sql',
            timeoutSeconds: (int) config('database-backup.timeout_seconds'),
        );
    }

    public static function containerPathFor(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $hostPath = rtrim((string) config('database-backup.host_path'), DIRECTORY_SEPARATOR);
        $containerPath = rtrim((string) config('database-backup.container_path'), DIRECTORY_SEPARATOR);

        if ($hostPath === '' || $containerPath === '' || $hostPath === $containerPath) {
            return $path;
        }

        if ($path !== $hostPath && ! str_starts_with($path, $hostPath.DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $containerPath.substr($path, strlen($hostPath));
    }

    public function destinationPath(): string
    {
        if ($this->subdirectory === '') {
            return $this->rootPath;
        }

        return $this->rootPath.DIRECTORY_SEPARATOR.$this->subdirectory;
    }
}
