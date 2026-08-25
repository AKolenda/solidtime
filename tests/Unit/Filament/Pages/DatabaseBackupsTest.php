<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Pages;

use App\Filament\Pages\DatabaseBackups;
use App\Models\User;
use App\Service\SelfHost\DatabaseBackupConfiguration;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Unit\Filament\FilamentTestCase;

#[UsesClass(DatabaseBackups::class)]
#[UsesClass(DatabaseBackupConfiguration::class)]
class DatabaseBackupsTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.super_admins', ['admin@example.com']);
        Config::set('database-backup.root_path', '/backups');
        $user = User::factory()->withPersonalOrganization()->create([
            'email' => 'admin@example.com',
        ]);

        $this->actingAs($user);
    }

    public function test_super_admin_can_edit_the_daily_backup_settings(): void
    {
        Livewire::test(DatabaseBackups::class)
            ->assertSuccessful()
            ->assertSee('/backups')
            ->fillForm([
                'enabled' => true,
                'time' => '03:15',
                'timezone' => 'America/Edmonton',
                'retention_days' => 45,
                'subdirectory' => 'daily',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('database_backup_settings', [
            'enabled' => true,
            'time' => '03:15',
            'timezone' => 'America/Edmonton',
            'retention_days' => 45,
            'subdirectory' => 'daily',
        ]);

        $configuration = DatabaseBackupConfiguration::load();
        $this->assertTrue($configuration->enabled);
        $this->assertSame('/backups/daily', $configuration->destinationPath());
    }
}
