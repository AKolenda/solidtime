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
}
