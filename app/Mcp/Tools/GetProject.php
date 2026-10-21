<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\ToolContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GetProject extends McpTool
{
    protected string $name = 'get_project';

    protected string $description = 'One project with estimate against actual hours, its tasks (each with estimate against actual), and the hours tracked on it per member.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->format('uuid')->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'project_id' => ['required', 'string', 'uuid'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $project = $context->api->send('GET', $context->path('projects/'.$input['project_id']))['data'] ?? [];
        $tasks = $context->api->all($context->path('tasks'), ['project_id' => $input['project_id'], 'done' => 'all']);
        $byMember = null;
        if ($context->can('time-entries:view:all')) {
            $aggregate = $context->api->send('GET', $context->path('time-entries/aggregate'), ['group' => 'user', 'project_ids' => [$input['project_id']]])['data'] ?? [];
            $names = Present::names(array_map(fn (array $group): array => ['user_id' => $group['key'] ?? null], $aggregate['grouped_data'] ?? []), $context)['members'];
            $byMember = array_map(fn (array $group): array => [
                'member_id' => $names[$group['key'] ?? '']['id'] ?? null,
                'member' => $names[$group['key'] ?? '']['name'] ?? null,
                'hours' => Present::hours($group['seconds'] ?? 0),
            ], $aggregate['grouped_data'] ?? []);
        }

        return Response::json([
            'project' => Present::project($project),
            'tasks' => array_map(fn (array $task): array => Present::task($task), $tasks),
            'hours_by_member' => $byMember,
        ]);
    }
}
