<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesProjects;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteTask extends WriteTool
{
    use FindsRecords;
    use WritesProjects;

    protected string $name = 'delete_task';

    protected string $description = 'Delete a task. SolidTime refuses while time entries still use it; mark it done with update_task instead. This cannot be undone.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()->format('uuid')->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'task_id' => ['required', 'string', 'uuid'],
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $task = $this->findTask($context, $input['task_id']);
        // Counting everyone's entries is only for someone who may see them
        $entries = $context->can('time-entries:view:all')
            ? TimeEntry::query()->whereBelongsTo($context->organization, 'organization')->where('task_id', $task['id'])->count() : null;
        $inUse = $entries === null ? ' (SolidTime refuses while time entries use it)'
            : ($entries > 0 ? " ({$entries} time entries use it, so SolidTime will refuse; mark it done instead)" : '');

        return new PlannedChange('Delete a task'.$inUse,
            'DELETE', $context->path('tasks/'.$task['id']), [], Present::task($task), null, fn (): array => ['deleted' => $task['id']]);
    }
}
