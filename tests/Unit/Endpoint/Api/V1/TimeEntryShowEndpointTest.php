<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Organization;
use App\Models\TimeEntry;
use Laravel\Passport\Passport;

class TimeEntryShowEndpointTest extends ApiEndpointTestAbstract
{
    public function test_own_time_entry_needs_view_own(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:own']);
        $entry = TimeEntry::factory()->forMember($data->member)->create();
        Passport::actingAs($data->user);

        $this->getJson(route('api.v1.time-entries.show', [$data->organization->id, $entry->id]))
            ->assertOk()->assertJsonPath('data.id', $entry->id)->assertJsonPath('data.user_id', $data->user->id);
    }

    public function test_another_members_time_entry_needs_view_all(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:own']);
        $entry = TimeEntry::factory()->forMember($data->ownerMember)->create();
        Passport::actingAs($data->user);

        $this->getJson(route('api.v1.time-entries.show', [$data->organization->id, $entry->id]))->assertForbidden();

        $all = $this->createUserWithPermission(['time-entries:view:all']);
        $other = TimeEntry::factory()->forMember($all->ownerMember)->create();
        Passport::actingAs($all->user);
        $this->getJson(route('api.v1.time-entries.show', [$all->organization->id, $other->id]))->assertOk();
    }

    public function test_time_entry_of_another_organization_is_refused(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:all']);
        $foreign = TimeEntry::factory()->forOrganization(Organization::factory()->create())->create();
        Passport::actingAs($data->user);

        $this->getJson(route('api.v1.time-entries.show', [$data->organization->id, $foreign->id]))->assertForbidden();
    }
}
