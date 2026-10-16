<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Models\Organization;
use App\Service\Import\Importers\ImporterContract;
use App\Service\Import\Importers\ImporterProvider;
use App\Service\Import\Importers\ImportException;
use App\Service\Import\Importers\ReportDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportService
{
    private const int IMPORT_COPY_RETENTION_DAYS = 30;

    /**
     * @throws ImportException
     */
    public function import(Organization $organization, string $importerType, string $data, string $timezone): ReportDto
    {
        /** @var ImporterContract $importer */
        $importer = app(ImporterProvider::class)->getImporter($importerType);
        $importer->init($organization);

        $lock = Cache::lock('import:'.$organization->getKey(), config('octane.max_execution_time', 60) + 1);

        if ($lock->get()) {
            try {
                DB::transaction(function () use (&$importer, &$data, &$timezone): void {
                    $importer->importData($data, $timezone);
                });
                // Only imported data is kept, so rejected or failed requests can not fill up the disk
                $this->storeImportCopy($organization, $data);
            } finally {
                $lock->release();
            }
        } else {
            throw new ImportException('Import is already in progress');
        }

        return $importer->getReport();
    }

    /**
     * Deletes the copies of import data that are older than the retention period, so they do not fill up the disk.
     *
     * @return int Number of deleted copies
     */
    public function pruneImportCopies(): int
    {
        $disk = Storage::disk(config('filesystems.private'));
        // The file names start with the date of the import, which sorts like the date itself
        $oldestKeptDate = Carbon::now()->subDays(self::IMPORT_COPY_RETENTION_DAYS)->toDateString();
        $deleted = 0;
        foreach ($disk->files('import') as $file) {
            if (substr(basename($file), 0, 10) < $oldestKeptDate) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Keeps a copy of the import data for debugging. The scheduled import:prune-copies command deletes
     * the copies after the retention period.
     * The import is already committed at this point, so a storage failure is only logged. Reporting the import as
     * failed would make the user retry it and import the data twice.
     */
    private function storeImportCopy(Organization $organization, string $data): void
    {
        try {
            Storage::disk(config('filesystems.private'))
                ->put('import/'.Carbon::now()->toDateString().'-'.$organization->getKey().'-'.Str::uuid(), $data);
        } catch (Throwable $exception) {
            Log::warning('Failed to store the copy of the import data', [
                'organization_id' => $organization->getKey(),
                'exception' => $exception,
            ]);
        }
    }
}
