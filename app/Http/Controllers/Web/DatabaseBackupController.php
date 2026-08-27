<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DatabaseBackupRun;
use App\Models\DatabaseBackupSetting;
use App\Service\SelfHost\DatabaseBackupConfiguration;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseBackupController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($request->user()?->canManageDatabaseBackups(), 403);

        $configuration = DatabaseBackupConfiguration::load();
        $runs = DatabaseBackupRun::query()->latest('started_at')->limit(10)->get();

        return Inertia::render('DatabaseBackups', [
            'settings' => [
                'enabled' => $configuration->enabled,
                'root_path' => $configuration->rootPath,
                'subdirectory' => $configuration->subdirectory,
                'time' => $configuration->time,
                'timezone' => $configuration->timezone,
                'retention_days' => $configuration->retentionDays,
                'output_format' => $configuration->outputFormat,
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'backupDirectory' => $this->backupDirectory($configuration),
            'backupFiles' => $this->backupFiles($configuration),
            'runs' => $runs->map(fn (DatabaseBackupRun $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'filename' => $run->filename,
                'size_bytes' => $run->size_bytes,
                'validated' => $run->validated,
                'error' => $run->error,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** @return array{path: string, exists: bool, readable: bool, writable: bool} */
    private function backupDirectory(DatabaseBackupConfiguration $configuration): array
    {
        $path = $configuration->destinationPath();

        return [
            'path' => $path,
            'exists' => is_dir($path),
            'readable' => is_dir($path) && is_readable($path),
            'writable' => is_dir($path) && is_writable($path),
        ];
    }

    /** @return list<array{name: string, size_bytes: int, modified_at: string}> */
    private function backupFiles(DatabaseBackupConfiguration $configuration): array
    {
        $directory = $configuration->destinationPath();
        if (! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $files = [];
        foreach (['dump', 'sql'] as $extension) {
            $matches = glob($directory.DIRECTORY_SEPARATOR.'solidtime-????????-??????.'.$extension);
            foreach ($matches === false ? [] : $matches as $path) {
                if (! is_file($path) || is_link($path)) {
                    continue;
                }

                $modifiedAt = filemtime($path);
                $size = filesize($path);
                if ($modifiedAt === false || $size === false) {
                    continue;
                }

                $files[] = [
                    'name' => basename($path),
                    'size_bytes' => $size,
                    'modified_at' => date(DATE_ATOM, $modifiedAt),
                ];
            }
        }

        usort($files, fn (array $left, array $right): int => strcmp($right['modified_at'], $left['modified_at']));

        return array_slice($files, 0, 500);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canManageDatabaseBackups(), 403);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'root_path' => ['required', 'string', 'max:1024', 'regex:/^\/.+/'],
            'subdirectory' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'output_format' => ['required', Rule::in(['dump', 'sql', 'both'])],
        ], [
            'root_path.regex' => 'The backup destination must be an absolute path visible inside the container.',
        ]);
        $data['root_path'] = rtrim($data['root_path'], DIRECTORY_SEPARATOR);
        $data['subdirectory'] = $data['subdirectory'] ?? '';

        DatabaseBackupSetting::query()->firstOrNew()->fill($data)->save();

        return back()->with('flash.banner', 'Database backup settings saved.');
    }

    public function restore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canManageDatabaseBackups(), 403);

        $data = $request->validate([
            'backup' => ['required', 'file', 'max:524288', 'extensions:dump,sql'],
            'confirmation' => ['required', 'in:RESTORE'],
        ], [
            'confirmation.in' => 'Type RESTORE to confirm replacing the current database.',
        ]);

        $file = $data['backup'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['dump', 'sql'], true)) {
            return back()->withErrors(['backup' => 'Choose a PostgreSQL .dump or .sql backup.']);
        }

        if (Artisan::call('self-host:backup-database') !== 0) {
            return back()->withErrors(['backup' => 'The safety backup failed, so the restore was cancelled.']);
        }

        $path = $file->storeAs('database-restore', 'restore-'.bin2hex(random_bytes(8)).'.'.$extension);
        $absolutePath = Storage::path($path);

        try {
            $connection = config('database-backup.connection') ?: config('database.default');
            $database = config("database.connections.{$connection}");
            abort_unless(is_array($database) && ($database['driver'] ?? null) === 'pgsql', 422);
            $environment = ['PGPASSWORD' => (string) ($database['password'] ?? '')];

            if ($extension === 'dump') {
                $validation = Process::timeout(120)->run(['pg_restore', '--list', $absolutePath]);
                if ($validation->failed()) {
                    return back()->withErrors(['backup' => 'The PostgreSQL archive is invalid.']);
                }
                $command = [
                    'pg_restore', '--clean', '--if-exists', '--single-transaction', '--no-owner',
                    '--no-privileges', '--host='.(string) $database['host'], '--port='.(string) $database['port'],
                    '--username='.(string) $database['username'], '--dbname='.(string) $database['database'], $absolutePath,
                ];
            } else {
                $header = file_get_contents($absolutePath, false, null, 0, 4096);
                if ($header === false || ! str_contains($header, 'PostgreSQL database dump')) {
                    return back()->withErrors(['backup' => 'The SQL file is not a recognizable PostgreSQL backup.']);
                }
                $command = [
                    'psql', '--single-transaction', '--set=ON_ERROR_STOP=1', '--host='.(string) $database['host'],
                    '--port='.(string) $database['port'], '--username='.(string) $database['username'],
                    '--dbname='.(string) $database['database'], '--file='.$absolutePath,
                ];
            }

            $restore = Process::env($environment)->timeout(1800)->run($command);
            if ($restore->failed()) {
                return back()->withErrors(['backup' => 'Restore failed: '.trim($restore->errorOutput() ?: $restore->output())]);
            }

            return back()->with('flash.banner', 'Database restored successfully.');
        } finally {
            @unlink($absolutePath);
        }
    }
}
