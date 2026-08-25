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

        return new self(
            enabled: $stored?->enabled ?? (bool) config('database-backup.enabled'),
            time: $stored?->time ?? (string) config('database-backup.time'),
            timezone: $stored?->timezone ?? (string) config('database-backup.timezone'),
            retentionDays: $stored?->retention_days ?? (int) config('database-backup.retention_days'),
            subdirectory: $stored?->subdirectory ?? '',
            rootPath: rtrim((string) config('database-backup.root_path'), DIRECTORY_SEPARATOR),
            timeoutSeconds: (int) config('database-backup.timeout_seconds'),
        );
    }

    public function destinationPath(): string
    {
        if ($this->subdirectory === '') {
            return $this->rootPath;
        }

        return $this->rootPath.DIRECTORY_SEPARATOR.$this->subdirectory;
    }
}
