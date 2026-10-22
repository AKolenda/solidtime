<?php

declare(strict_types=1);

namespace App\Console\Commands\TimeEntry;

use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Models\Organization;
use App\Models\TimeEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TimeEntryAutoStopCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'time-entry:auto-stop '.
        ' { --dry-run : Do not actually save anything to the database, just output what would happen }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stops time entries that have been running longer than their organization\'s auto-stop limit.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->comment('Stopping time entries that ran past the auto-stop limit...');
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->comment('Running in dry-run mode. Nothing will be saved to the database.');
        }

        $stopped = 0;
        $organizations = Organization::query()
            ->whereNotNull('time_entry_auto_stop_hours')
            ->get();

        foreach ($organizations as $organization) {
            /** @var int $hours */
            $hours = $organization->time_entry_auto_stop_hours;
            TimeEntry::query()
                ->whereBelongsTo($organization, 'organization')
                ->whereNull('end')
                ->where('start', '<', Carbon::now()->subHours($hours))
                ->chunkById(500, function (Collection $timeEntries) use ($dryRun, $hours, &$stopped): void {
                    /** @var Collection<int, TimeEntry> $timeEntries */
                    foreach ($timeEntries as $timeEntry) {
                        if ($dryRun) {
                            $this->info('Stop time entry '.$timeEntry->getKey().' of user '.$timeEntry->user_id.' after '.$hours.' hours');
                            $stopped++;

                            continue;
                        }
                        // Lock and re-read the entry, a user may have stopped or corrected it since the chunk was loaded
                        $stoppedTimeEntry = DB::transaction(function () use ($timeEntry, $hours): ?TimeEntry {
                            /** @var TimeEntry|null $lockedTimeEntry */
                            $lockedTimeEntry = TimeEntry::query()
                                ->whereKey($timeEntry->getKey())
                                ->whereNull('end')
                                ->where('start', '<', Carbon::now()->subHours($hours))
                                ->lockForUpdate()
                                ->first();
                            if ($lockedTimeEntry === null) {
                                return null;
                            }
                            // End the entry at the limit, not at the time the command ran
                            $lockedTimeEntry->end = $lockedTimeEntry->start->copy()->addHours($hours);
                            $lockedTimeEntry->save();

                            return $lockedTimeEntry;
                        });
                        if ($stoppedTimeEntry === null) {
                            continue;
                        }
                        $this->info('Stop time entry '.$stoppedTimeEntry->getKey().' of user '.$stoppedTimeEntry->user_id.' after '.$hours.' hours');
                        $stopped++;
                        if ($stoppedTimeEntry->project !== null) {
                            RecalculateSpentTimeForProject::dispatch($stoppedTimeEntry->project);
                        }
                        if ($stoppedTimeEntry->task !== null) {
                            RecalculateSpentTimeForTask::dispatch($stoppedTimeEntry->task);
                        }
                    }
                });
        }

        $this->comment('Finished stopping '.$stopped.' time entries...');

        return self::SUCCESS;
    }
}
