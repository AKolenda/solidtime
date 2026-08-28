<?php

declare(strict_types=1);

namespace App\Service\SelfHost;

use FilesystemIterator;
use Throwable;

class DatabaseBackupMountDiscovery
{
    /** @return list<array{path: string, mount_path: string, recommended: bool}> */
    public function discover(string $configuredDestination): array
    {
        $candidates = [];

        foreach ($this->mountPoints() as $mountPath) {
            if (! $this->isDataMount($mountPath) || ! is_dir($mountPath) || ! is_readable($mountPath)) {
                continue;
            }

            if (is_writable($mountPath)) {
                $candidates[$mountPath] = [
                    'path' => $mountPath,
                    'mount_path' => $mountPath,
                    'recommended' => false,
                ];
            }

            try {
                $inspected = 0;
                foreach (new FilesystemIterator($mountPath, FilesystemIterator::SKIP_DOTS) as $item) {
                    if (++$inspected > 200) {
                        break;
                    }

                    if (! $item->isDir() || $item->isLink() || ! $item->isReadable() || ! $item->isWritable()) {
                        continue;
                    }

                    $path = $this->normalizePath($item->getPathname());
                    $candidates[$path] = [
                        'path' => $path,
                        'mount_path' => $mountPath,
                        'recommended' => false,
                    ];
                }
            } catch (Throwable) {
                // A mount can disappear or become unreadable while the page is loading.
            }

            $configuredDestination = $this->normalizePath($configuredDestination);
            if (
                $this->isWithin($configuredDestination, $mountPath)
                && is_dir($configuredDestination)
                && is_readable($configuredDestination)
                && is_writable($configuredDestination)
            ) {
                $candidates[$configuredDestination] = [
                    'path' => $configuredDestination,
                    'mount_path' => $mountPath,
                    'recommended' => false,
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $recommendedPath = $this->recommendedPath(array_keys($candidates), $configuredDestination);
        $candidates[$recommendedPath]['recommended'] = true;
        $result = array_values($candidates);
        usort($result, fn (array $left, array $right): int => $left['recommended'] === $right['recommended']
            ? strnatcasecmp($left['path'], $right['path'])
            : ($left['recommended'] ? -1 : 1));

        return array_slice($result, 0, 100);
    }

    /** @return list<string> */
    private function mountPoints(): array
    {
        $mountInfoPath = (string) config('database-backup.mountinfo_path', '/proc/self/mountinfo');
        $contents = @file_get_contents($mountInfoPath);
        if ($contents === false) {
            return [];
        }

        $mountPoints = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim(explode(' - ', $line, 2)[0]));
            if (! isset($fields[4]) || ! str_starts_with($fields[4], '/')) {
                continue;
            }

            $mountPoint = $this->normalizePath((string) preg_replace_callback(
                '/\\\\([0-7]{3})/',
                fn (array $matches): string => chr(octdec($matches[1])),
                $fields[4]
            ));
            $mountPoints[$mountPoint] = true;
        }

        return array_keys($mountPoints);
    }

    private function isDataMount(string $path): bool
    {
        $configuredContainerPath = $this->normalizePath((string) config('database-backup.container_path', '/backups'));
        if ($this->isWithin($path, $configuredContainerPath)) {
            return true;
        }

        foreach ((array) config('database-backup.discovery_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && $this->isWithin($path, $this->normalizePath($prefix))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $paths */
    private function recommendedPath(array $paths, string $configuredDestination): string
    {
        if (in_array($configuredDestination, $paths, true)) {
            return $configuredDestination;
        }

        $containerPath = $this->normalizePath((string) config('database-backup.container_path', '/backups'));
        $conventionalPath = $containerPath.DIRECTORY_SEPARATOR.'solidtime_backups';
        if (in_array($conventionalPath, $paths, true)) {
            return $conventionalPath;
        }

        foreach ($paths as $path) {
            $name = strtolower(basename($path));
            if (str_contains($name, 'solidtime') && str_contains($name, 'backup')) {
                return $path;
            }
        }

        return in_array($containerPath, $paths, true) ? $containerPath : $paths[0];
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        if ($path === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
