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
                ->with([
                    'project',
                    'task',
                ])
                ->chunkById(500, function (Collection $timeEntries) use ($dryRun, $hours, &$stopped): void {
                    /** @var Collection<int, TimeEntry> $timeEntries */
                    foreach ($timeEntries as $timeEntry) {
                        $this->info('Stop time entry '.$timeEntry->getKey().' of user '.$timeEntry->user_id.' after '.$hours.' hours');
                        $stopped++;
                        if (! $dryRun) {
                            // End the entry at the limit, not at the time the command ran
                            $timeEntry->end = $timeEntry->start->copy()->addHours($hours);
                            $timeEntry->save();
                            if ($timeEntry->project !== null) {
                                RecalculateSpentTimeForProject::dispatch($timeEntry->project);
                            }
                            if ($timeEntry->task !== null) {
                                RecalculateSpentTimeForTask::dispatch($timeEntry->task);
                            }
                        }
                    }
                });
        }

        $this->comment('Finished stopping '.$stopped.' time entries...');

        return self::SUCCESS;
    }
}
