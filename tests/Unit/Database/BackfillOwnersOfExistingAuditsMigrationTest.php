<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Audit;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCaseWithDatabase;

class BackfillOwnersOfExistingAuditsMigrationTest extends TestCaseWithDatabase
{
    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_06_000003_backfill_owners_of_existing_audits.php');
        $migration->up();
    }

    public function test_up_fills_in_the_owners_of_audits_recorded_before_the_owner_columns_existed(): void
    {
        // Arrange
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $member = Member::factory()->forOrganization($organization)->forUser($user)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $project = Project::factory()->forOrganization($organization)->forClient($client)->create();
        Task::factory()->forOrganization($organization)->forProject($project)->create();
        Tag::factory()->forOrganization($organization)->create();
        TimeEntry::factory()->forOrganization($organization)->forMember($member)->forProject($project)->create();
        ProjectMember::factory()->forProject($project)->forMember($member)->create();
        OrganizationInvitation::factory()->forOrganization($otherOrganization)->create();
        // The audits recorded by the models above have the owners a new audit gets
        $expectedOwners = Audit::query()
            ->orderBy('id')
            ->get(['id', 'owner_organization_id', 'owner_user_id'])
            ->mapWithKeys(fn (Audit $audit): array => [$audit->id => [$audit->owner_organization_id, $audit->owner_user_id]])
            ->all();
        $this->assertNotEmpty($expectedOwners);
        DB::table('audits')->update(['owner_organization_id' => null, 'owner_user_id' => null]);

        // Act
        $this->runMigration();

        // Assert
        $owners = Audit::query()
            ->orderBy('id')
            ->get(['id', 'owner_organization_id', 'owner_user_id'])
            ->mapWithKeys(fn (Audit $audit): array => [$audit->id => [$audit->owner_organization_id, $audit->owner_user_id]])
            ->all();
        $this->assertSame($expectedOwners, $owners);
        $this->assertSame(0, Audit::query()->whereNull('owner_organization_id')->whereNull('owner_user_id')->count());
    }

    public function test_up_keeps_audits_of_deleted_models_without_owner_and_does_not_change_existing_owners(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $member = Member::factory()->forOrganization($organization)->create();
        $deletedTimeEntry = TimeEntry::factory()->forOrganization($organization)->forMember($member)->create();
        $timeEntry = TimeEntry::factory()->forOrganization($organization)->forMember($member)->create();
        DB::table('audits')->where('auditable_id', $deletedTimeEntry->getKey())->update(['owner_organization_id' => null]);
        DB::table('time_entries')->where('id', $deletedTimeEntry->getKey())->delete();
        // Not the organization of the time entry, so the test notices if the migration overwrites it
        DB::table('audits')->where('auditable_id', $timeEntry->getKey())->update(['owner_organization_id' => $otherOrganization->getKey()]);

        // Act
        $this->runMigration();

        // Assert
        $this->assertNull(Audit::query()->where('auditable_id', $deletedTimeEntry->getKey())->sole()->owner_organization_id);
        $this->assertSame($otherOrganization->getKey(), Audit::query()->where('auditable_id', $timeEntry->getKey())->sole()->owner_organization_id);
    }
}
