<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Project;
use App\Service\PermissionStore;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class CreationIdempotencyEndpointTest extends ApiEndpointTestAbstract
{
    private array $headers = ['Idempotency-Key' => 'calendar-project-0001'];

    private function payload(): array
    {
        return ['name' => 'Retry job', 'color' => '#112233', 'is_billable' => true, 'client_id' => null];
    }

    public function test_project_replays_exact_response_and_canonical_payload_after_response_is_lost(): void
    {
        $data = $this->createUserWithPermission(['projects:create', 'projects:view']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $first = $this->postJson($url, $this->payload(), $this->headers)->assertCreated()->assertHeader('X-Solidtime-Idempotency', 'v1');
        $retry = $this->postJson($url, array_reverse($this->payload(), true), $this->headers)->assertCreated();
        $this->assertSame($first->json(), $retry->json());
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('api_creation_operations', 1);
        $this->getJson(route('api.v1.projects.index', [$data->organization->id]))->assertOk();
    }

    public function test_mismatched_payload_is_rejected_without_creating_another_project(): void
    {
        $data = $this->createUserWithPermission(['projects:create']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $this->postJson($url, $this->payload(), $this->headers)->assertCreated();
        $this->postJson($url, [...$this->payload(), 'name' => 'Changed'], $this->headers)->assertConflict();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_replay_rechecks_project_permission(): void
    {
        $data = $this->createUserWithPermission(['projects:create']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $this->postJson($url, $this->payload(), $this->headers)->assertCreated();
        PermissionStore::registerCustomRole($data->member->role, []);
        app(PermissionStore::class)->clear();
        $this->postJson($url, $this->payload(), $this->headers)->assertForbidden();
    }

    public function test_invalid_payload_does_not_reserve_key_and_can_be_corrected(): void
    {
        $data = $this->createUserWithPermission(['projects:create']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $this->postJson($url, [...$this->payload(), 'name' => ''], $this->headers)->assertUnprocessable();
        $this->assertDatabaseCount('api_creation_operations', 0);
        $this->postJson($url, $this->payload(), $this->headers)->assertCreated();
    }

    public function test_record_write_failure_rolls_back_created_project_and_allows_retry(): void
    {
        $data = $this->createUserWithPermission(['projects:create']);
        Passport::actingAs($data->user);
        DB::statement('ALTER TABLE api_creation_operations ADD CONSTRAINT test_record_failure CHECK (response_status <> 201)');
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $this->postJson($url, $this->payload(), $this->headers)->assertStatus(500);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('api_creation_operations', 0);
        DB::statement('ALTER TABLE api_creation_operations DROP CONSTRAINT test_record_failure');
        $this->postJson($url, $this->payload(), $this->headers)->assertCreated();
    }

    public function test_task_replays_and_rechecks_permission(): void
    {
        $data = $this->createUserWithPermission(['tasks:create:all']);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $url = route('api.v1.tasks.store', [$data->organization->id]);
        $payload = ['name' => 'Cut', 'project_id' => $project->id];
        $first = $this->postJson($url, $payload, $this->headers)->assertCreated();
        $retry = $this->postJson($url, $payload, $this->headers)->assertCreated()->assertHeader('X-Solidtime-Idempotency', 'v1');
        $this->assertSame($first->json(), $retry->json());
        $this->assertDatabaseCount('tasks', 1);
        PermissionStore::registerCustomRole($data->member->role, []);
        app(PermissionStore::class)->clear();
        $this->postJson($url, $payload, $this->headers)->assertForbidden();
    }

    public function test_dedicated_routes_require_a_key_and_replay_successful_creations(): void
    {
        $data = $this->createUserWithPermission(['projects:create', 'tasks:create:all']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store-idempotent', [$data->organization->id]);
        $this->postJson($url, $this->payload())->assertUnprocessable();
        $first = $this->postJson($url, $this->payload(), $this->headers)->assertCreated();
        $this->assertSame($first->json(), $this->postJson($url, $this->payload(), $this->headers)->assertCreated()->json());
        $taskUrl = route('api.v1.tasks.store-idempotent', [$data->organization->id]);
        $task = ['name' => 'Cut', 'project_id' => $first->json('data.id')];
        $this->postJson($taskUrl, $task)->assertUnprocessable();
        $firstTask = $this->postJson($taskUrl, $task, $this->headers)->assertCreated();
        $this->assertSame($firstTask->json(), $this->postJson($taskUrl, $task, $this->headers)->assertCreated()->json());
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_task_replay_rechecks_current_project_visibility(): void
    {
        $data = $this->createUserWithPermission(['tasks:create']);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $url = route('api.v1.tasks.store-idempotent', [$data->organization->id]);
        $payload = ['name' => 'Cut', 'project_id' => $project->id];
        $this->postJson($url, $payload, $this->headers)->assertCreated();
        $project->update(['is_public' => false]);
        $this->postJson($url, $payload, $this->headers)->assertForbidden();
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_key_validation_and_unchanged_unkeyed_creation(): void
    {
        $data = $this->createUserWithPermission(['projects:create']);
        Passport::actingAs($data->user);
        $url = route('api.v1.projects.store', [$data->organization->id]);
        $this->postJson($url, $this->payload(), ['Idempotency-Key' => 'short'])->assertUnprocessable();
        $this->postJson($url, $this->payload())->assertCreated()->assertHeaderMissing('X-Solidtime-Idempotency');
        $this->assertDatabaseCount('api_creation_operations', 0);
    }

    public function test_time_entry_replays_and_creates_only_one_entry(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $url = route('api.v1.time-entries.store-idempotent', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'project_id' => $project->id, 'start' => '2026-10-01T08:00:00Z', 'end' => '2026-10-01T10:00:00Z', 'billable' => false, 'description' => 'Turning'];
        $this->postJson($url, $payload)->assertUnprocessable();
        $first = $this->postJson($url, $payload, $this->headers)->assertCreated();
        $retry = $this->postJson($url, array_reverse($payload, true), $this->headers)->assertCreated()->assertHeader('X-Solidtime-Idempotency', 'v1');
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame('Turning', $first->json('data.description'));
        $this->assertSame($project->id, $first->json('data.project_id'));
        $this->assertDatabaseCount('time_entries', 1);
        $this->postJson($url, [...$payload, 'description' => 'Milling'], $this->headers)->assertConflict();
        $this->assertDatabaseCount('time_entries', 1);
    }

    public function test_running_time_entry_replay_returns_the_same_timer(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.store', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'start' => '2026-10-01T08:00:00Z', 'end' => null, 'billable' => false];
        $first = $this->postJson($url, $payload, $this->headers)->assertCreated();
        $this->assertSame($first->json(), $this->postJson($url, $payload, $this->headers)->assertCreated()->json());
        // Another key is another creation, refused while the first timer runs
        $this->postJson($url, $payload, ['Idempotency-Key' => 'calendar-project-0002'])->assertStatus(400);
        $this->assertDatabaseCount('time_entries', 1);
    }

    public function test_time_entry_replay_rechecks_permission_and_validates_member(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.store', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'start' => '2026-10-01T08:00:00Z', 'end' => '2026-10-01T09:00:00Z', 'billable' => false];
        $this->postJson($url, ['member_id' => 'not-a-member', 'start' => '2026-10-01T08:00:00Z'], $this->headers)->assertUnprocessable();
        $this->postJson($url, [...$payload, 'member_id' => $data->ownerMember->id], $this->headers)->assertForbidden();
        $this->postJson($url, $payload, $this->headers)->assertCreated();
        PermissionStore::registerCustomRole($data->member->role, []);
        app(PermissionStore::class)->clear();
        $this->postJson($url, $payload, $this->headers)->assertForbidden();
        $this->assertDatabaseCount('time_entries', 1);
    }

    public function test_unkeyed_time_entry_creation_is_unchanged(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.store', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'start' => '2026-10-01T08:00:00Z', 'end' => '2026-10-01T09:00:00Z', 'billable' => false];
        $this->postJson($url, $payload)->assertCreated()->assertHeaderMissing('X-Solidtime-Idempotency');
        $this->postJson($url, [...$payload, 'start' => '2026-10-01T10:00:00Z', 'end' => '2026-10-01T11:00:00Z'])->assertCreated();
        $this->assertDatabaseCount('time_entries', 2);
        $this->assertDatabaseCount('api_creation_operations', 0);
    }

    public function test_replay_only_returns_an_earlier_creation_and_never_creates(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.store-idempotent', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'start' => '2026-10-01T08:00:00Z', 'end' => '2026-10-01T09:00:00Z', 'billable' => false];

        $this->postJson($url, $payload, [...$this->headers, 'Idempotency-Replay-Only' => '1'])->assertNotFound()->assertHeader('X-Solidtime-Idempotency', 'none');
        $this->assertDatabaseCount('time_entries', 0);
        $first = $this->postJson($url, $payload, $this->headers)->assertCreated();
        $this->assertSame($first->json(), $this->postJson($url, $payload, [...$this->headers, 'Idempotency-Replay-Only' => '1'])->assertCreated()->json());
        $this->assertDatabaseCount('time_entries', 1);

        // Without the key there is nothing to recover, and nothing is created
        $this->postJson(route('api.v1.time-entries.store', [$data->organization->id]), $payload, ['Idempotency-Replay-Only' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        $this->assertDatabaseCount('time_entries', 1);
    }
}
