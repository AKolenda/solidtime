<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Mcp;

use App\Enums\Role;
use App\Models\McpToolCall;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCaseWithDatabase;

class SolidtimeMcpServerTest extends TestCaseWithDatabase
{
    private int $id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $tool, array $arguments = [], array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => ++$this->id,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function ok(string $tool, array $arguments = []): array
    {
        $response = $this->callTool($tool, $arguments)->assertOk();
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function error(string $tool, array $arguments = []): string
    {
        $response = $this->callTool($tool, $arguments)->assertOk();
        $this->assertTrue($response->json('result.isError'));

        return (string) $response->json('result.content.0.text');
    }

    /**
     * @return object{user: User, organization: Organization, member: Member}
     */
    private function teammate(Organization $organization, Role $role = Role::Employee): object
    {
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->role($role)->create();
        $user->currentOrganization()->associate($organization);
        $user->save();

        return (object) ['user' => $user, 'organization' => $organization, 'member' => $member];
    }

    public function test_tools_are_listed_with_annotations_and_closed_schemas(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $tools = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]])
            ->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertCount(21, $tools);
        $this->assertTrue($tools['list_time_entries']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['delete_time_entry']['annotations']['destructiveHint']);
        $this->assertArrayNotHasKey('readOnlyHint', $tools['start_timer']['annotations']);
        $this->assertFalse($tools['start_timer']['annotations']['destructiveHint']);
        $this->assertArrayNotHasKey('destructiveHint', $tools['update_time_entry']['annotations']);
        $this->assertSame('boolean', $tools['start_timer']['inputSchema']['properties']['confirm']['type']);
        $this->assertFalse($tools['start_timer']['inputSchema']['additionalProperties']);
        $this->assertArrayNotHasKey('confirm', $tools['list_projects']['inputSchema']['properties']);
    }

    public function test_requires_a_signed_in_user(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();
    }

    public function test_answers_only_on_the_internal_network(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $this->callTool('get_context', [], ['REMOTE_ADDR' => '203.0.113.10'])->assertNotFound();
        $this->withHeader('Cf-Ray', '8a1b2c3d4e5f-YYC');
        $this->callTool('get_context')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:4567/callback']])->assertNotFound();
        $this->assertSame(0, McpToolCall::query()->count());
    }

    public function test_get_context_returns_the_member_and_permissions(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $context = $this->ok('get_context');

        $this->assertSame($data->member->id, $context['member']['id']);
        $this->assertSame($data->organization->id, $context['organization']['id']);
        $this->assertContains('time-entries:view:own', $context['permissions']);
        $this->assertNotContains('time-entries:view:all', $context['permissions']);
    }

    public function test_employee_sees_own_time_entries_but_not_everyone_else(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $other = $this->teammate($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->isPublic()->create(['name' => 'Job 4410']);
        TimeEntry::factory()->forMember($data->member)->forProject($project)->startWithDuration(Carbon::parse('2026-10-01 08:00:00'), 5400)->create(['description' => 'Turning']);
        TimeEntry::factory()->forMember($other->member)->startWithDuration(Carbon::parse('2026-10-01 08:00:00'), 3600)->create();
        Passport::actingAs($data->user);

        $mine = $this->ok('list_time_entries', ['member_ids' => [$data->member->id]]);
        $this->assertSame(1, $mine['count']);
        $this->assertEquals(1.5, $mine['entries'][0]['hours']);
        $this->assertSame('Job 4410', $mine['entries'][0]['project']);
        $this->assertSame($data->member->id, $mine['entries'][0]['member_id']);

        $this->assertStringContainsString('permission', $this->error('list_time_entries'));
        $this->assertSame('denied', McpToolCall::query()->latest('created_at')->first()->outcome);
    }

    public function test_summarize_time_groups_hours_by_project_with_names(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Job 4410']);
        TimeEntry::factory()->forMember($data->member)->forProject($project)->startWithDuration(Carbon::parse('2026-10-01 08:00:00'), 7200)->create();
        TimeEntry::factory()->forMember($data->member)->forProject($project)->startWithDuration(Carbon::parse('2026-10-02 08:00:00'), 1800)->create();
        Passport::actingAs($data->user);

        $summary = $this->ok('summarize_time', ['group' => 'project', 'sub_group' => 'user', 'start' => '2026-10-01T00:00:00-06:00', 'end' => '2026-10-31T00:00:00-06:00']);

        $this->assertEquals(2.5, $summary['total_hours']);
        $this->assertSame('Job 4410', $summary['groups'][0]['name']);
        $this->assertEquals(2.5, $summary['groups'][0]['groups'][0]['hours']);
        $this->assertSame($data->user->name, $summary['groups'][0]['groups'][0]['name']);
    }

    public function test_active_timers_cover_everyone_for_admins_and_only_yours_for_employees(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $employee = $this->teammate($data->organization);
        TimeEntry::factory()->forMember($data->member)->active()->create();
        TimeEntry::factory()->forMember($employee->member)->active()->create();

        Passport::actingAs($data->user);
        $all = $this->ok('list_active_timers');
        $this->assertSame('everyone', $all['scope']);
        $this->assertCount(2, $all['timers']);

        Passport::actingAs($employee->user);
        $own = $this->ok('list_active_timers');
        $this->assertSame('yours', $own['scope']);
        $this->assertCount(1, $own['timers']);
        $this->assertSame($employee->member->id, $own['timers'][0]['member_id']);
    }

    public function test_projects_show_estimate_against_actual_hours_and_tasks(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Job 4410', 'estimated_time' => 36000, 'spent_time' => 45000]);
        Project::factory()->forOrganization($data->organization)->create(['name' => 'Job 5000', 'estimated_time' => 36000, 'spent_time' => 3600]);
        Task::factory()->forProject($project)->forOrganization($data->organization)->create(['name' => 'Turning', 'estimated_time' => 7200, 'spent_time' => 3600]);
        Passport::actingAs($data->user);

        $over = $this->ok('list_projects', ['over_estimate' => true]);
        $this->assertSame(['Job 4410'], array_column($over['projects'], 'name'));
        $this->assertEquals(10.0, $over['projects'][0]['estimated_hours']);
        $this->assertEquals(12.5, $over['projects'][0]['actual_hours']);
        $this->assertEquals(-2.5, $over['projects'][0]['remaining_hours']);

        $detail = $this->ok('get_project', ['project_id' => $project->id]);
        $this->assertSame('Turning', $detail['tasks'][0]['name']);
        $this->assertEquals(50.0, $detail['tasks'][0]['percent_of_estimate']);
    }

    public function test_start_timer_previews_until_confirmed_with_true(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $project = Project::factory()->forOrganization($data->organization)->billable()->isPublic()->create();
        Passport::actingAs($data->user);
        $arguments = ['project_id' => $project->id, 'description' => 'Setup', 'start' => '2026-10-21T07:00:00-06:00'];

        $preview = $this->ok('start_timer', $arguments);
        $this->assertTrue($preview['preview']);
        $this->assertTrue($preview['after']['billable']);
        $this->assertSame('2026-10-21T13:00:00Z', $preview['after']['start']);
        $this->ok('start_timer', [...$arguments, 'confirm' => 'true']);
        $this->assertSame(0, TimeEntry::query()->count());

        $applied = $this->ok('start_timer', [...$arguments, 'confirm' => true]);
        $this->assertTrue($applied['applied']);
        $this->assertTrue($applied['result']['running']);
        $entry = TimeEntry::query()->sole();
        $this->assertNull($entry->end);
        $this->assertSame('Setup', $entry->description);
        $this->assertSame(['preview', 'preview', 'ok'], McpToolCall::query()->orderBy('created_at')->pluck('outcome')->all());
        $this->assertSame(['length' => 5], McpToolCall::query()->orderByDesc('created_at')->first()->arguments['description']);

        $this->assertStringContainsString('running', $this->error('start_timer', [...$arguments, 'start' => '2026-10-21T08:00:00-06:00', 'confirm' => true]));
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_request_id_makes_a_creation_safe_to_retry(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);
        $arguments = ['start' => '2026-10-20T08:00:00Z', 'end' => '2026-10-20T10:00:00Z', 'request_id' => 'erp-action-1234567890', 'confirm' => true];

        $first = $this->ok('create_time_entry', $arguments);
        $retry = $this->ok('create_time_entry', $arguments);

        $this->assertSame($first['result']['id'], $retry['result']['id']);
        $this->assertEquals(2.0, $retry['result']['hours']);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_stop_timer_stops_your_running_timer(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $running = TimeEntry::factory()->forMember($data->member)->create(['start' => Carbon::parse('2026-10-21 13:00:00'), 'end' => null]);
        Passport::actingAs($data->user);

        $preview = $this->ok('stop_timer', ['end' => '2026-10-21T09:30:00-06:00']);
        $this->assertTrue($preview['before']['running']);
        $this->assertEquals(2.5, $preview['after']['hours']);
        $this->assertNull($running->refresh()->end);

        $this->assertStringContainsString('"time_entry_id":"'.$running->id.'"', $preview['next_step']);
        $this->assertStringContainsString('time_entry_id from the preview', $this->error('stop_timer', ['end' => '2026-10-21T09:30:00-06:00', 'confirm' => true]));
        $this->ok('stop_timer', ['end' => '2026-10-21T09:30:00-06:00', 'time_entry_id' => $running->id, 'confirm' => true]);
        $this->assertSame('2026-10-21 15:30:00', $running->refresh()->end->toDateTimeString());
        $this->assertSame('You have no running timer in this organization.', $this->error('stop_timer'));
    }

    public function test_api_rules_apply_to_confirmed_changes(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $data->organization->update(['prevent_overlapping_time_entries' => true]);
        $other = $this->teammate($data->organization);
        TimeEntry::factory()->forMember($data->member)->startWithDuration(Carbon::parse('2026-10-20 08:00:00'), 7200)->create();
        $foreign = TimeEntry::factory()->forMember($other->member)->startWithDuration(Carbon::parse('2026-10-20 08:00:00'), 3600)->create();
        Passport::actingAs($data->user);

        // Overlap with an existing entry is refused by the API
        $this->error('create_time_entry', ['start' => '2026-10-20T09:00:00Z', 'end' => '2026-10-20T11:00:00Z', 'confirm' => true]);
        $this->assertSame(2, TimeEntry::query()->count());
        // Another member's time is neither visible nor changeable for an employee
        $this->assertStringContainsString('not visible', $this->error('update_time_entry', ['time_entry_id' => $foreign->id, 'description' => 'x', 'confirm' => true]));
        $this->error('create_time_entry', ['member_id' => $other->member->id, 'start' => '2026-10-22T08:00:00Z', 'end' => '2026-10-22T09:00:00Z', 'confirm' => true]);
        $this->assertSame(2, TimeEntry::query()->count());
    }

    public function test_update_and_delete_time_entries(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $entry = TimeEntry::factory()->forMember($data->member)->startWithDuration(Carbon::parse('2026-10-20 08:00:00'), 3600)->create(['description' => 'Old']);
        Passport::actingAs($data->user);

        $preview = $this->ok('update_time_entry', ['time_entry_id' => $entry->id, 'description' => 'Deburr', 'end' => '2026-10-20T10:00:00Z']);
        $this->assertSame('Old', $preview['before']['description']);
        $this->assertEquals(2.0, $preview['after']['hours']);
        $this->ok('update_time_entry', ['time_entry_id' => $entry->id, 'description' => 'Deburr', 'end' => '2026-10-20T10:00:00Z', 'confirm' => true]);
        $this->assertSame('Deburr', $entry->refresh()->description);

        $this->ok('delete_time_entry', ['time_entry_id' => $entry->id]);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->ok('delete_time_entry', ['time_entry_id' => $entry->id, 'confirm' => true]);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_project_and_task_changes(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $this->actAsOrganizationWithSubscription();
        Passport::actingAs($data->user);

        $project = $this->ok('create_project', ['name' => 'Job 7781', 'estimated_hours' => 12.5, 'is_billable' => true, 'confirm' => true])['result'];
        $this->assertEquals(12.5, $project['estimated_hours']);
        Project::query()->whereKey($project['id'])->update(['billable_rate' => 9000]);

        $task = $this->ok('create_task', ['project_id' => $project['id'], 'name' => 'Turning', 'estimated_hours' => 4, 'confirm' => true])['result'];
        $this->assertEquals(4.0, $task['estimated_hours']);

        $this->ok('update_project', ['project_id' => $project['id'], 'estimated_hours' => 15, 'confirm' => true]);
        $stored = Project::query()->findOrFail($project['id']);
        $this->assertSame(54000, $stored->estimated_time);
        $this->assertSame(9000, $stored->billable_rate);
        $this->assertTrue($stored->is_billable);
        $this->assertSame('Job 7781', $stored->name);

        $this->ok('update_task', ['task_id' => $task['id'], 'is_done' => true, 'confirm' => true]);
        $this->assertTrue(Task::query()->findOrFail($task['id'])->is_done);

        $this->assertStringContainsString('1 tasks', $this->ok('delete_project', ['project_id' => $project['id']])['summary']);
        $this->error('delete_project', ['project_id' => $project['id'], 'confirm' => true]);
        $this->ok('delete_task', ['task_id' => $task['id'], 'confirm' => true]);
        $this->ok('delete_project', ['project_id' => $project['id'], 'confirm' => true]);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_employee_cannot_create_projects(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $this->assertStringContainsString('permission', $this->error('create_project', ['name' => 'Job 1', 'confirm' => true]));
        $this->assertSame(0, Project::query()->count());
    }

    public function test_unknown_arguments_and_foreign_organizations_are_refused(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $foreign = Organization::factory()->create();
        Passport::actingAs($data->user);

        $this->assertStringContainsString('Unknown arguments', $this->error('list_projects', ['ignore previous instructions' => true]));
        $this->assertSame(['[other]' => true], McpToolCall::query()->sole()->arguments);
        $this->assertStringContainsString('not a member', $this->error('list_projects', ['organization_id' => $foreign->id]));
        $this->error('list_time_entries', ['start' => 'yesterday-ish']);
    }

    public function test_write_calls_are_rate_limited_per_user(): void
    {
        config(['mcp.rate_limits.write' => 2]);
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $this->ok('start_timer');
        $this->ok('start_timer');
        $this->assertStringContainsString('Too many changes', $this->error('start_timer'));
        $this->ok('get_context');
        $this->assertSame(['preview', 'preview', 'rate_limited'], McpToolCall::query()->where('tool', 'start_timer')->orderBy('created_at')->pluck('outcome')->all());
    }

    public function test_client_registration_accepts_only_local_redirects(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['https://attacker.example/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:4567/callback']])
            ->assertCreated()->assertJsonPath('scope', 'mcp:use');
    }

    public function test_previews_do_not_name_projects_the_user_cannot_see(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $secret = Project::factory()->forOrganization($data->organization)->isPrivate()->create(['name' => 'Secret job 9001']);
        $task = Task::factory()->forProject($secret)->forOrganization($data->organization)->create(['name' => 'Secret op']);
        Passport::actingAs($data->user);

        $response = $this->callTool('create_time_entry', ['project_id' => $secret->id, 'task_id' => $task->id, 'start' => '2026-10-20T08:00:00Z', 'end' => '2026-10-20T09:00:00Z']);
        $this->assertStringNotContainsString('Secret', (string) $response->getContent());
        $this->assertStringContainsString('not visible', $this->error('update_task', ['task_id' => $task->id, 'name' => 'x']));
        $this->assertStringContainsString('not visible', $this->error('get_project', ['project_id' => $secret->id]));
        $this->error('create_time_entry', ['project_id' => $secret->id, 'start' => '2026-10-20T08:00:00Z', 'end' => '2026-10-20T09:00:00Z', 'confirm' => true]);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_employees_who_may_manage_tasks_can_use_the_task_tools(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $data->organization->update(['employees_can_manage_tasks' => true]);
        $project = Project::factory()->forOrganization($data->organization)->isPublic()->create(['name' => 'Job 7781']);
        Passport::actingAs($data->user);

        $this->assertSame('Job 7781', $this->ok('get_project', ['project_id' => $project->id])['project']['name']);
        $task = $this->ok('create_task', ['project_id' => $project->id, 'name' => 'Turning', 'confirm' => true])['result'];
        $this->ok('update_task', ['task_id' => $task['id'], 'name' => 'Turning OP10', 'confirm' => true]);
        $this->assertSame('Turning OP10', Task::query()->findOrFail($task['id'])->name);
        $this->ok('delete_task', ['task_id' => $task['id'], 'confirm' => true]);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_start_timer_retry_with_request_id_replays_across_time(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);
        $arguments = ['description' => 'Setup', 'request_id' => 'chat-run-000000000001', 'confirm' => true];

        $this->travelTo(Carbon::parse('2026-10-21 14:00:00'));
        $first = $this->ok('start_timer', $arguments)['result'];
        $this->travelTo(Carbon::parse('2026-10-21 14:03:20'));
        $retry = $this->ok('start_timer', $arguments)['result'];

        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame('2026-10-21T14:00:00Z', $retry['start']);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_deletion_previews_count_entries_only_for_those_who_see_everyones_time(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $employee = $this->teammate($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->isPublic()->create();
        $task = Task::factory()->forProject($project)->forOrganization($data->organization)->create();
        TimeEntry::factory()->forMember($data->member)->forProject($project)->forTask($task)->count(3)->create();

        Passport::actingAs($employee->user);
        $this->assertStringNotContainsString('3 time entries', $this->ok('delete_project', ['project_id' => $project->id])['summary']);
        $this->assertStringNotContainsString('3 time entries', $this->ok('delete_task', ['task_id' => $task->id])['summary']);

        Passport::actingAs($data->user);
        $this->assertStringContainsString('3 time entries', $this->ok('delete_task', ['task_id' => $task->id])['summary']);
    }

    public function test_project_search_covers_more_projects_than_twenty_pages(): void
    {
        config(['app.pagination_per_page_default' => 2]);
        $data = $this->createUserWithRole(Role::Admin);
        Project::factory()->forOrganization($data->organization)->count(44)->create();
        Project::factory()->forOrganization($data->organization)->create(['name' => '905080158 - 7531 - 2pcs', 'created_at' => now()->subYear()]);
        Passport::actingAs($data->user);

        $found = $this->ok('list_projects', ['search' => '905080158']);
        $this->assertSame(['905080158 - 7531 - 2pcs'], array_column($found['projects'], 'name'));
        $this->assertSame(45, $this->ok('list_projects')['count']);
    }

    public function test_task_names_follow_the_api_limit(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $project = Project::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $this->assertStringContainsString('255', $this->error('create_task', ['project_id' => $project->id, 'name' => str_repeat('x', 256)]));
        $this->assertSame(0, Task::query()->count());
    }

    public function test_stop_timer_finds_the_running_timer_of_the_selected_organization(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $other = Organization::factory()->create();
        $otherMember = Member::factory()->forUser($data->user)->forOrganization($other)->role(Role::Employee)->create();
        TimeEntry::factory()->forMember($otherMember)->create(['start' => Carbon::parse('2026-10-21 13:00:00'), 'end' => null]);
        Passport::actingAs($data->user);

        $this->assertStringContainsString('no running timer in this organization', $this->error('stop_timer'));
        $this->assertTrue($this->ok('stop_timer', ['organization_id' => $other->id])['before']['running']);
    }

    public function test_estimated_hours_stay_within_what_the_api_stores(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $this->actAsOrganizationWithSubscription();
        Passport::actingAs($data->user);

        $this->error('create_project', ['name' => 'Job 1', 'estimated_hours' => 600000]);
        $this->assertSame(596523.0, (float) $this->ok('create_project', ['name' => 'Job 1', 'estimated_hours' => 596523])['after']['estimated_hours']);
    }

    public function test_mcp_is_off_unless_enabled(): void
    {
        config(['mcp.enabled' => false]);
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $this->callTool('get_context')->assertNotFound();
    }

    public function test_every_forwarded_address_must_be_internal(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        $this->withHeader('X-Forwarded-For', '10.0.0.5, 203.0.113.9');
        $this->callTool('get_context')->assertNotFound();
        $this->withHeader('X-Forwarded-For', '10.0.0.5');
        $this->ok('get_context');
        $this->withHeader('Forwarded', 'for="[2001:db8::1]:443"');
        $this->callTool('get_context')->assertNotFound();
    }

    public function test_forwarded_ipv6_addresses_count_with_brackets_and_ports(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        Passport::actingAs($data->user);

        foreach (['[fd00::5]:443', '[fd00::5]', 'fd00::5', '10.0.0.5:8080'] as $internal) {
            $this->withHeaders(['X-Forwarded-For' => $internal, 'X-Real-IP' => $internal]);
            $this->ok('get_context');
        }
        foreach (['[2001:db8::1]:443', '[2001:db8::1]', '2001:db8::1', '203.0.113.9:8080'] as $public) {
            $this->withHeaders(['X-Forwarded-For' => '10.0.0.5', 'X-Real-IP' => $public]);
            $this->callTool('get_context')->assertNotFound();
        }
    }

    public function test_previews_never_promise_what_the_plan_does_not_store(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $project = Project::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $this->assertStringContainsString('estimated hours', $this->error('create_project', ['name' => 'Job 1', 'estimated_hours' => 10]));
        $this->assertStringContainsString('estimated hours', $this->error('update_project', ['project_id' => $project->id, 'estimated_hours' => null]));
        $this->actAsOrganizationWithoutBillableRates();
        $this->assertStringContainsString('billable', $this->error('start_timer', ['billable' => true]));
        $this->assertStringContainsString('billable', $this->error('create_project', ['name' => 'Job 2', 'is_billable' => true]));
        $this->assertFalse($this->ok('start_timer', ['project_id' => $project->id])['after']['billable']);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_confirmed_stop_never_reaches_a_timer_started_after_the_preview(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $first = TimeEntry::factory()->forMember($data->member)->create(['start' => Carbon::parse('2026-10-21 13:00:00'), 'end' => null]);
        Passport::actingAs($data->user);

        $this->ok('stop_timer');
        $first->update(['end' => Carbon::parse('2026-10-21 14:00:00')]);
        $second = TimeEntry::factory()->forMember($data->member)->create(['start' => Carbon::parse('2026-10-21 14:05:00'), 'end' => null]);

        $this->error('stop_timer', ['time_entry_id' => $first->id, 'confirm' => true]);
        $this->assertNull($second->refresh()->end);
    }

    public function test_changing_only_the_task_keeps_the_project(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        $project = Project::factory()->forOrganization($data->organization)->create();
        [$turning, $milling] = Task::factory()->forProject($project)->forOrganization($data->organization)->count(2)->create();
        $entry = TimeEntry::factory()->forMember($data->member)->forProject($project)->forTask($turning)->create();
        $loose = TimeEntry::factory()->forMember($data->member)->create(['project_id' => null, 'task_id' => null]);
        Passport::actingAs($data->user);

        $this->ok('update_time_entry', ['time_entry_id' => $entry->id, 'task_id' => $milling->id, 'confirm' => true]);
        $this->assertSame($milling->id, $entry->refresh()->task_id);
        $this->assertStringContainsString('project_id too', $this->error('update_time_entry', ['time_entry_id' => $loose->id, 'task_id' => $milling->id]));
    }

    public function test_entries_name_their_tasks_across_many_projects(): void
    {
        $data = $this->createUserWithRole(Role::Admin);
        foreach (range(1, 27) as $index) {
            $project = Project::factory()->forOrganization($data->organization)->create();
            $task = Task::factory()->forProject($project)->forOrganization($data->organization)->create(['name' => 'Op '.$index]);
            TimeEntry::factory()->forMember($data->member)->forProject($project)->forTask($task)
                ->startWithDuration(Carbon::parse('2026-10-01 08:00:00')->addHours($index), 600)->create();
        }
        Passport::actingAs($data->user);

        $entries = $this->ok('list_time_entries', ['limit' => 100])['entries'];
        $this->assertCount(27, $entries);
        $this->assertSame([], array_filter($entries, fn (array $entry): bool => $entry['task'] === null));
    }
}
