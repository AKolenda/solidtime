<?php

declare(strict_types=1);

namespace Tests\Unit\Service\ShopReport;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Service\ShopReport\ProjectReportSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_grouped_parts_and_calculates_setup_and_running_per_piece(): void
    {
        $project = Project::factory()->create([
            'name' => 'M4718+M4719 - 62554 - 5+10pcs - QM0+1.5 - QT2+3.5',
        ]);
        $setup = Task::factory()->forProject($project)->create(['name' => 'Turning - Programming, Setup and F/O']);
        $running = Task::factory()->forProject($project)->create(['name' => 'Turning - Running']);
        TimeEntry::factory()->forTask($setup)->create(['start' => '2026-08-25 08:00:00', 'end' => '2026-08-25 09:00:00']);
        TimeEntry::factory()->forTask($running)->create(['start' => '2026-08-25 09:00:00', 'end' => '2026-08-25 11:30:00']);

        $entries = TimeEntry::query()->with('task')->get();
        $summary = ProjectReportSummary::from($project->name, $entries);

        $this->assertSame('62554', $summary->purchaseOrder);
        $this->assertSame(['M4718', 'M4719'], array_column($summary->parts, 'part'));
        $this->assertSame([5.0, 10.0], array_column($summary->parts, 'quantity'));
        $this->assertSame([2.0, 3.5], array_column($summary->parts, 'turning'));
        $this->assertSame([0.0, 1.5], array_column($summary->parts, 'milling'));
        $this->assertSame(15.0, $summary->totalQuantity);
        $this->assertSame(3600, $summary->operations[0]['setup_seconds']);
        $this->assertSame(9000, $summary->operations[0]['running_seconds']);
        $this->assertSame(600.0, $summary->operations[0]['seconds_per_piece']);
    }
}
