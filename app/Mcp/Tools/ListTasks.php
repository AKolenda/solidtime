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
class ListTasks extends McpTool
{
    protected string $name = 'list_tasks';

    protected string $description = 'Tasks, optionally of one project, with estimated against actual hours. Open tasks by default.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->format('uuid')->description('Only tasks of this project.'),
            'done' => $schema->string()->enum(['false', 'true', 'all'])->description('Open tasks (default), done ones, or all.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'string', 'uuid'],
            'done' => ['sometimes', 'string', 'in:false,true,all'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $query = ['done' => $input['done'] ?? 'false'];
        if (isset($input['project_id'])) {
            $query['project_id'] = $input['project_id'];
        }
        $tasks = array_map(fn (array $task): array => Present::task($task), $context->api->all($context->path('tasks'), $query));

        return Response::json(['tasks' => array_slice($tasks, 0, 300), 'count' => count($tasks), 'truncated' => count($tasks) > 300]);
    }
}
