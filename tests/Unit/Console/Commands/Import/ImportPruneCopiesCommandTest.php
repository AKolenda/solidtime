<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Import;

use App\Console\Commands\Import\ImportPruneCopiesCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(ImportPruneCopiesCommand::class)]
class ImportPruneCopiesCommandTest extends TestCaseWithDatabase
{
    public function test_it_deletes_import_copies_older_than_the_retention_period(): void
    {
        // Arrange
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        Storage::fake(config('filesystems.private'));
        $disk = Storage::disk(config('filesystems.private'));
        $disk->put('import/2026-09-14-old-copy', 'old');
        $disk->put('import/2026-09-15-kept-copy', 'kept');

        // Act
        $this->artisan('import:prune-copies')
            ->expectsOutput('Deleted 1 import data copies.')
            ->assertSuccessful();

        // Assert
        $this->assertSame(['import/2026-09-15-kept-copy'], $disk->files('import'));
    }
}
