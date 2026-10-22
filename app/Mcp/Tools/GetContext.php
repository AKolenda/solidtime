<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use App\Models\Member;
use App\Service\PermissionStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GetContext extends McpTool
{
    protected string $name = 'get_context';

    protected string $description = 'Who you are signed in as, your organizations, your member ID and role, and your permissions in the organization. Call this first.';

    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $memberships = Member::query()->where('user_id', $context->user->getKey())->with('organization')->get()
            ->map(fn (Member $member): array => ['organization_id' => $member->organization_id, 'organization' => $member->organization->name, 'member_id' => $member->getKey(), 'role' => $member->role])
            ->values()->all();

        return Response::json([
            'user' => ['id' => $context->user->getKey(), 'name' => $context->user->name, 'timezone' => $context->user->timezone],
            'organization' => ['id' => $context->organization->getKey(), 'name' => $context->organization->name, 'currency' => $context->organization->currency],
            'member' => ['id' => $context->member->getKey(), 'role' => $context->member->role],
            'permissions' => app(PermissionStore::class)->getPermissions($context->organization),
            'memberships' => $memberships,
            'times' => 'Answers give times in UTC. Convert times the user gives in their own timezone ('.$context->user->timezone.') to ISO 8601 with an offset.',
        ]);
    }
}
