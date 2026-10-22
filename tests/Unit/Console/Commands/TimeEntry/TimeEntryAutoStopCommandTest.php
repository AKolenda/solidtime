<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\TimeEntry;

use App\Console\Commands\TimeEntry\TimeEntryAutoStopCommand;
use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(TimeEntryAutoStopCommand::class)]
class TimeEntryAutoStopCommandTest extends TestCaseWithDatabase
{
    public function test_stops_running_time_entries_at_the_organizations_limit(): void
    {
        // Arrange
        Queue::fake([
            RecalculateSpentTimeForProject::class,
            RecalculateSpentTimeForTask::class,
        ]);
        $this->travelTo(Carbon::create(2024, 1, 2, 12, 0, 0, 'UTC'));
        $user = $this->createUserWithPermission();
        $user->organization->time_entry_auto_stop_hours = 16;
        $user->organization->save();
        $project = Project::factory()->forOrganization($user->organization)->create();
        $task = Task::factory()->forOrganization($user->organization)->forProject($project)->create();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->forTask($task)->active()->create([
            'start' => Carbon::now()->subHours(17),
        ]);

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('time-entry:auto-stop');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $timeEntry->refresh();
        $this->assertSame('2024-01-02T11:00:00Z', $timeEntry->end?->toIso8601ZuluString());
        Queue::assertPushed(RecalculateSpentTimeForProject::class, 1);
        Queue::assertPushed(RecalculateSpentTimeForTask::class, 1);
        $output = Artisan::output();
        $this->assertSame("Stopping time entries that ran past the auto-stop limit...\n".
            'Stop time entry '.$timeEntry->getKey().' of user '.$user->user->getKey()." after 16 hours\n".
            "Finished stopping 1 time entries...\n", $output);
    }

    public function test_does_not_stop_running_time_entries_below_the_limit(): void
    {
        // Arrange
        $user = $this->createUserWithPermission();
        $user->organization->time_entry_auto_stop_hours = 16;
        $user->organization->save();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->active()->create([
            'start' => Carbon::now()->subHours(16)->addMinute(),
        ]);

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('time-entry:auto-stop');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
    }

    public function test_does_not_stop_time_entries_of_organizations_without_a_limit(): void
    {
        // Arrange
        $user = $this->createUserWithPermission();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->active()->create([
            'start' => Carbon::now()->subDays(3),
        ]);

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('time-entry:auto-stop');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
        $output = Artisan::output();
        $this->assertSame("Stopping time entries that ran past the auto-stop limit...\n".
            "Finished stopping 0 time entries...\n", $output);
    }

    public function test_dry_run_option_does_not_stop_time_entries_but_outputs_what_would_happen(): void
    {
        // Arrange
        $user = $this->createUserWithPermission();
        $user->organization->time_entry_auto_stop_hours = 8;
        $user->organization->save();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->active()->create([
            'start' => Carbon::now()->subHours(9),
        ]);

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('time-entry:auto-stop --dry-run');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
        $output = Artisan::output();
        $this->assertSame("Stopping time entries that ran past the auto-stop limit...\n".
            "Running in dry-run mode. Nothing will be saved to the database.\n".
            'Stop time entry '.$timeEntry->getKey().' of user '.$user->user->getKey()." after 8 hours\n".
            "Finished stopping 1 time entries...\n", $output);
    }
}
