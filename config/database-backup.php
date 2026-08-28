<?php

declare(strict_types=1);

return [
    'connection' => env('DATABASE_BACKUP_CONNECTION'),
    'enabled' => env('DATABASE_BACKUP_ENABLED', false),
    'host_path' => env('DATABASE_BACKUP_HOST_PATH'),
    'container_path' => env('DATABASE_BACKUP_CONTAINER_PATH', '/backups'),
    'root_path' => env('DATABASE_BACKUP_ROOT_PATH', '/backups/solidtime_backups'),
    'time' => env('DATABASE_BACKUP_TIME', '02:00'),
    'timezone' => env('DATABASE_BACKUP_TIMEZONE', 'UTC'),
    'retention_days' => env('DATABASE_BACKUP_RETENTION_DAYS', 30),
    'timeout_seconds' => env('DATABASE_BACKUP_TIMEOUT_SECONDS', 1800),
];
