<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Member;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListMembers extends McpTool
{
    protected string $name = 'list_members';

    protected string $description = 'People in the organization with their member IDs and roles. Time-entry tools take member IDs, not user IDs. Search narrows by name.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(255)->description('Only members whose name contains this text.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        if (! $context->can('members:view')) {
            throw new ToolError('You do not have permission to see the members.', 403);
        }
        // As MemberController::index
        $query = Member::query()->whereBelongsTo($context->organization, 'organization')->with('user');
        if (isset($input['search']) && trim($input['search']) !== '') {
            $query->whereHas('user', fn ($user) => $user->where('name', 'ilike', '%'.addcslashes(trim($input['search']), '%_\\').'%'));
        }
        $count = (clone $query)->count();
        $members = $query->orderBy('created_at', 'desc')->orderBy('id')->limit(500)->get();

        return Response::json([
            'members' => $members->map(fn (Member $member): array => [
                'member_id' => $member->getKey(),
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'role' => $member->role,
                'is_placeholder' => $member->user->is_placeholder,
            ])->all(),
            'count' => $count,
            'truncated' => $count > 500,
        ]);
    }
}
