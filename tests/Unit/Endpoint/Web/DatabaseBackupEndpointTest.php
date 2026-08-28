<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Web;

use App\Enums\Role;
use App\Http\Controllers\Web\DatabaseBackupController;
use App\Models\DatabaseBackupSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseBackupController::class)]
class DatabaseBackupEndpointTest extends EndpointTestAbstract
{
    public function test_organization_admin_can_open_the_native_backup_page(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $this->actingAs($data->user);

        $this->get(route('database-backups.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DatabaseBackups')
                ->where('settings.timezone', 'UTC')
                ->where('settings.root_path', '/backups/solidtime_backups')
                ->has('timezones', fn (Assert $timezones) => $timezones
                    ->where('0', 'Africa/Abidjan')
                    ->etc())
                ->where('backupDirectory.path', '/backups/solidtime_backups')
                ->has('backupFiles')
                ->has('runs')
            );
    }

    public function test_legacy_host_backup_path_is_changed_to_the_container_path(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        config([
            'database-backup.host_path' => '/mnt/solidtime_backups',
            'database-backup.container_path' => '/backups',
        ]);
        DatabaseBackupSetting::query()->create([
            'enabled' => true,
            'root_path' => '/mnt/solidtime_backups/solidtime_backups',
            'subdirectory' => '',
            'time' => '02:00',
            'timezone' => 'America/Edmonton',
            'retention_days' => 30,
            'output_format' => 'both',
        ]);

        $this->actingAs($data->user)
            ->get(route('database-backups.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.root_path', '/backups/solidtime_backups')
                ->where('backupDirectory.host_path', '/mnt/solidtime_backups')
                ->where('backupDirectory.container_path', '/backups')
            );

        $this->assertDatabaseHas('database_backup_settings', [
            'root_path' => '/backups/solidtime_backups',
        ]);
    }

    public function test_backup_page_lists_only_completed_backup_files_from_the_destination(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solidtime-backups-'.bin2hex(random_bytes(6));
        mkdir($directory);

        try {
            $older = $directory.DIRECTORY_SEPARATOR.'solidtime-20260826-020000.dump';
            $newer = $directory.DIRECTORY_SEPARATOR.'solidtime-20260827-020000.sql';
            file_put_contents($older, 'archive');
            file_put_contents($newer, '-- PostgreSQL database dump');
            file_put_contents($directory.DIRECTORY_SEPARATOR.'solidtime-20260827-020000.sql.partial', 'partial');
            file_put_contents($directory.DIRECTORY_SEPARATOR.'notes.txt', 'unrelated');
            touch($older, 1_000);
            touch($newer, 2_000);

            DatabaseBackupSetting::query()->create([
                'enabled' => true,
                'root_path' => $directory,
                'subdirectory' => '',
                'time' => '02:00',
                'timezone' => 'America/Edmonton',
                'retention_days' => 30,
                'output_format' => 'both',
            ]);

            $this->actingAs($data->user)
                ->get(route('database-backups.show'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('backupDirectory.path', $directory)
                    ->where('backupDirectory.exists', true)
                    ->where('backupDirectory.readable', true)
                    ->has('backupFiles', 2)
                    ->where('backupFiles.0.name', basename($newer))
                    ->where('backupFiles.1.name', basename($older))
                );
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_employee_cannot_open_the_backup_page(): void
    {
        $data = $this->createUserWithRole(Role::Employee);

        $this->actingAs($data->user)
            ->get(route('database-backups.show'))
            ->assertForbidden();
    }

    public function test_admin_can_save_the_backup_destination_and_timezone(): void
    {
        $data = $this->createUserWithRole(Role::Admin);

        $this->actingAs($data->user)
            ->put(route('database-backups.update'), [
                'enabled' => true,
                'root_path' => '/backups/secondary',
                'subdirectory' => 'solidtime',
                'time' => '04:30',
                'timezone' => 'America/Edmonton',
                'retention_days' => 45,
                'output_format' => 'both',
            ])
            ->assertRedirect();

        $settings = DatabaseBackupSetting::query()->firstOrFail();
        $this->assertSame('/backups/secondary', $settings->root_path);
        $this->assertSame('America/Edmonton', $settings->timezone);
        $this->assertSame(45, $settings->retention_days);
        $this->assertSame('both', $settings->output_format);
    }

    public function test_admin_can_restore_a_valid_dump_after_a_safety_backup(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        Artisan::shouldReceive('call')->once()->with('self-host:backup-database')->andReturn(0);
        Process::fake()->preventStrayProcesses();

        $this->actingAs($data->user)
            ->post(route('database-backups.restore'), [
                'backup' => UploadedFile::fake()->createWithContent('solidtime.dump', 'custom archive'),
                'confirmation' => 'RESTORE',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'pg_restore'
            && $process->command[1] === '--list');
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'pg_restore'
            && in_array('--clean', $process->command, true));
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $data = $this->createUserWithRole(Role::Admin);

        $this->actingAs($data->user)
            ->post(route('database-backups.restore'), [
                'backup' => UploadedFile::fake()->createWithContent('solidtime.sql', '-- PostgreSQL database dump'),
                'confirmation' => 'yes',
            ])
            ->assertSessionHasErrors('confirmation');
    }
}
