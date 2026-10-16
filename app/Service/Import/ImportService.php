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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                $this->storeImportCopy($organization, $data);
                DB::transaction(function () use (&$importer, &$data, &$timezone): void {
                    $importer->importData($data, $timezone);
                });
            } finally {
                $lock->release();
            }
        } else {
            throw new ImportException('Import is already in progress');
        }

        return $importer->getReport();
    }

    /**
     * Keeps a copy of the import data for debugging. Copies older than the retention period are deleted,
     * so the copies do not fill up the disk.
     */
    private function storeImportCopy(Organization $organization, string $data): void
    {
        $disk = Storage::disk(config('filesystems.private'));
        $disk->put('import/'.Carbon::now()->toDateString().'-'.$organization->getKey().'-'.Str::uuid(), $data);

        // The file names start with the date of the import, which sorts like the date itself
        $oldestKeptDate = Carbon::now()->subDays(self::IMPORT_COPY_RETENTION_DAYS)->toDateString();
        foreach ($disk->files('import') as $file) {
            if (substr(basename($file), 0, 10) < $oldestKeptDate) {
                $disk->delete($file);
            }
        }
    }
}
