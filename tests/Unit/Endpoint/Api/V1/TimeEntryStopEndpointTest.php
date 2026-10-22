<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Exceptions\Api\TimeEntryIsNotRunningApiException;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(TimeEntryController::class)]
class TimeEntryStopEndpointTest extends ApiEndpointTestAbstract
{
    public function test_stop_endpoint_stops_the_running_time_entry_of_another_member_with_update_all_permission(): void
    {
        // Arrange
        Queue::fake([
            RecalculateSpentTimeForProject::class,
            RecalculateSpentTimeForTask::class,
        ]);
        $this->travelTo(Carbon::create(2024, 1, 1, 12, 0, 0, 'UTC'));
        $data = $this->createUserWithPermission([
            'time-entries:update:all',
        ]);
        $otherMember = Member::factory()->forOrganization($data->organization)->create();
        $project = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->forProject($project)->create();
        $timeEntry = TimeEntry::factory()->forOrganization($data->organization)->forMember($otherMember)->forTask($task)->active()->create([
            'start' => Carbon::now()->subHours(3),
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertOk();
        $response->assertJsonPath('data.id', $timeEntry->getKey());
        $response->assertJsonPath('data.end', '2024-01-01T12:00:00Z');
        $timeEntry->refresh();
        $this->assertSame('2024-01-01T12:00:00Z', $timeEntry->end?->toIso8601ZuluString());
        Queue::assertPushed(RecalculateSpentTimeForProject::class, 1);
        Queue::assertPushed(RecalculateSpentTimeForTask::class, 1);
    }

    public function test_stop_endpoint_stops_the_own_running_time_entry_with_update_own_permission(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:own',
        ]);
        $timeEntry = TimeEntry::factory()->forOrganization($data->organization)->forMember($data->member)->active()->create([
            'start' => Carbon::now()->subHour(),
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertOk();
        $timeEntry->refresh();
        $this->assertNotNull($timeEntry->end);
    }

    public function test_stop_endpoint_fails_if_the_time_entry_was_already_stopped_and_keeps_its_end(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:all',
        ]);
        $otherMember = Member::factory()->forOrganization($data->organization)->create();
        $end = Carbon::now()->subHour()->startOfSecond();
        $timeEntry = TimeEntry::factory()->forOrganization($data->organization)->forMember($otherMember)->create([
            'start' => Carbon::now()->subHours(3),
            'end' => $end,
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertStatus(400);
        $response->assertJsonPath('key', TimeEntryIsNotRunningApiException::KEY);
        $timeEntry->refresh();
        $this->assertSame($end->toIso8601ZuluString(), $timeEntry->end?->toIso8601ZuluString());
    }

    public function test_stop_endpoint_fails_if_the_time_entry_starts_in_the_future(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:own',
        ]);
        $timeEntry = TimeEntry::factory()->forOrganization($data->organization)->forMember($data->member)->active()->create([
            'start' => Carbon::now()->addHour(),
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end']);
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
    }

    public function test_stop_endpoint_fails_if_user_can_only_update_own_time_entries(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:own',
        ]);
        $otherMember = Member::factory()->forOrganization($data->organization)->create();
        $timeEntry = TimeEntry::factory()->forOrganization($data->organization)->forMember($otherMember)->active()->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertForbidden();
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
    }

    public function test_stop_endpoint_fails_if_time_entry_belongs_to_different_organization_than_url(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:all',
        ]);
        $otherOrganization = $this->createUserWithPermission([], true);
        $timeEntry = TimeEntry::factory()->forOrganization($otherOrganization->organization)->forMember($otherOrganization->ownerMember)->active()->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.stop', [$data->organization->getKey(), $timeEntry->getKey()]));

        // Assert
        $response->assertForbidden();
        $timeEntry->refresh();
        $this->assertNull($timeEntry->end);
    }
}
