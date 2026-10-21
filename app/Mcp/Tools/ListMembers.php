<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ToolContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListMembers extends McpTool
{
    protected string $name = 'list_members';

    protected string $description = 'People in the organization with their member IDs and roles. Time-entry tools take member IDs, not user IDs.';

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
        $members = $context->api->all($context->path('members'));

        return Response::json(['members' => array_map(fn (array $member): array => [
            'member_id' => $member['id'] ?? null,
            'user_id' => $member['user_id'] ?? null,
            'name' => $member['name'] ?? null,
            'role' => $member['role'] ?? null,
            'is_placeholder' => $member['is_placeholder'] ?? null,
        ], $members)]);
    }
}
