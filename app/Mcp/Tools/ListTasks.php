<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Resources\V1\Task\TaskResource;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Task;
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
        $query = $context->visibleTaskQuery();
        if ($query === null) {
            throw new ToolError('You do not have permission to see tasks.', 403);
        }
        if (isset($input['project_id'])) {
            $query->where('project_id', $input['project_id']);
        }
        $done = $input['done'] ?? 'false';
        if ($done !== 'all') {
            $done === 'true' ? $query->whereNotNull('done_at') : $query->whereNull('done_at');
        }
        $count = (clone $query)->count();
        $tasks = $query->orderBy('created_at', 'desc')->orderBy('id')->limit(300)->get()
            ->map(fn (Task $task): array => Present::task((new TaskResource($task))->toArray(request())))->all();

        return Response::json(['tasks' => $tasks, 'count' => $count, 'truncated' => $count > 300]);
    }
}
