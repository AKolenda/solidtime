<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Models whose audits belong to the organization in their own organization_id column, keyed by morph alias.
     *
     * @var array<string, string>
     */
    private const array ORGANIZATION_OWNED_TABLES = [
        'client' => 'clients',
        'membership' => 'members',
        'organization-invitation' => 'organization_invitations',
        'project' => 'projects',
        'tag' => 'tags',
        'task' => 'tasks',
        'time-entry' => 'time_entries',
    ];

    /**
     * Run the migrations.
     *
     * Audits recorded before the owner columns existed have no owner, so deleting their organization or user would
     * not delete them. Fill in the owner the same way new audits get it (see CustomAuditable).
     * Audits of models that no longer exist keep no owner, since it can not be derived anymore.
     */
    public function up(): void
    {
        foreach (self::ORGANIZATION_OWNED_TABLES as $morphAlias => $table) {
            DB::statement(
                'UPDATE audits SET owner_organization_id = owner.organization_id
                FROM '.$table.' AS owner
                WHERE audits.auditable_type = ?
                  AND audits.auditable_id = owner.id
                  AND audits.owner_organization_id IS NULL',
                [$morphAlias]
            );
        }

        // Project members belong to the organization of their project
        DB::statement(
            "UPDATE audits SET owner_organization_id = projects.organization_id
            FROM project_members
            JOIN projects ON projects.id = project_members.project_id
            WHERE audits.auditable_type = 'project-member'
              AND audits.auditable_id = project_members.id
              AND audits.owner_organization_id IS NULL"
        );

        // Organizations and users own their own audits
        DB::statement(
            "UPDATE audits SET owner_organization_id = organizations.id
            FROM organizations
            WHERE audits.auditable_type = 'organization'
              AND audits.auditable_id = organizations.id
              AND audits.owner_organization_id IS NULL"
        );
        DB::statement(
            "UPDATE audits SET owner_user_id = users.id
            FROM users
            WHERE audits.auditable_type = 'user'
              AND audits.auditable_id = users.id
              AND audits.owner_user_id IS NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The owners are also set for new audits, so they can not be told apart from the backfilled ones
    }
};
