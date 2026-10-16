<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Import;

use App\Models\Organization;
use App\Service\Import\Importers\ImporterProvider;
use App\Service\Import\Importers\ImportException;
use App\Service\Import\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ImportService::class)]
#[CoversClass(ImporterProvider::class)]
class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_gets_importer_from_provider_runs_importer_and_returns_report(): void
    {
        // Arrange
        Storage::fake(config('filesystems.private'));
        $organization = Organization::factory()->create();
        $timezone = 'Europe/Vienna';
        $data = Storage::disk('testfiles')->get('toggl_time_entries_import_test_1.csv');

        // Act
        $importService = app(ImportService::class);
        $report = $importService->import($organization, 'toggl_time_entries', $data, $timezone);

        // Assert
        $lock = Cache::lock('import:'.$organization->getKey());
        $this->assertTrue($lock->get());
        $this->assertSame(2, $report->timeEntriesCreated);
        $this->assertSame(2, $report->tagsCreated);
        $this->assertSame(1, $report->tasksCreated);
        $this->assertSame(1, $report->usersCreated);
        $this->assertSame(2, $report->projectsCreated);
        $this->assertSame(1, $report->clientsCreated);
        $storedFiles = Storage::disk(config('filesystems.private'))->files('import');
        $this->assertCount(1, $storedFiles);
        $this->assertStringStartsWith('import/'.Carbon::now()->toDateString().'-'.$organization->getKey().'-', $storedFiles[0]);
        $this->assertSame($data, Storage::disk(config('filesystems.private'))->get($storedFiles[0]));
    }

    public function test_import_releases_lock_if_an_exception_happens_during_the_import(): void
    {
        // Arrange
        Storage::fake(config('filesystems.private'));
        $organization = Organization::factory()->create();
        $timezone = 'Europe/Vienna';
        $data = 'Invalid CSV data';

        // Act
        $importService = app(ImportService::class);
        try {
            $importService->import($organization, 'toggl_time_entries', $data, $timezone);
        } catch (ImportException) {
            // Assert
            $lock = Cache::lock('import:'.$organization->getKey());
            $this->assertTrue($lock->get());
        }
    }

    public function test_import_throws_exception_if_import_is_already_in_progress(): void
    {
        // Arrange
        Storage::fake(config('filesystems.private'));
        $organization = Organization::factory()->create();
        $timezone = 'Europe/Vienna';
        $data = Storage::disk('testfiles')->get('toggl_time_entries_import_test_1.csv');
        Cache::lock('import:'.$organization->getKey(), 10)->get();

        // Act
        $importService = app(ImportService::class);
        try {
            $importService->import($organization, 'toggl_time_entries', $data, $timezone);
            $this->fail('The import did not throw an exception');
        } catch (ImportException $e) {
            // Assert
            $this->assertSame('Import is already in progress', $e->getMessage());
            $this->assertSame([], Storage::disk(config('filesystems.private'))->files('import'));
        }
    }

    public function test_import_deletes_copies_of_import_data_older_than_the_retention_period(): void
    {
        // Arrange
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        Storage::fake(config('filesystems.private'));
        $disk = Storage::disk(config('filesystems.private'));
        $disk->put('import/2026-09-14-old-copy', 'old');
        $disk->put('import/2026-09-15-kept-copy', 'kept');
        $organization = Organization::factory()->create();
        $data = Storage::disk('testfiles')->get('toggl_time_entries_import_test_1.csv');

        // Act
        app(ImportService::class)->import($organization, 'toggl_time_entries', $data, 'Europe/Vienna');

        // Assert
        $storedFiles = $disk->files('import');
        $this->assertCount(2, $storedFiles);
        $this->assertNotContains('import/2026-09-14-old-copy', $storedFiles);
        $this->assertContains('import/2026-09-15-kept-copy', $storedFiles);
    }
}
