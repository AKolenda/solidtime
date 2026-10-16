<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Service\Import\ImportService;
use Illuminate\Console\Command;

class ImportPruneCopiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:prune-copies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes the copies of import data that are older than the retention period.';

    /**
     * Execute the console command.
     */
    public function handle(ImportService $importService): int
    {
        $deleted = $importService->pruneImportCopies();
        $this->info('Deleted '.$deleted.' import data copies.');

        return self::SUCCESS;
    }
}
