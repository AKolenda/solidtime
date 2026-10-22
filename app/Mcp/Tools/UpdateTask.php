<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\LocksPreviewedRecord;
use App\Mcp\Tools\Concerns\WritesProjects;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTask extends WriteTool
{
    use FindsRecords;
    use LocksPreviewedRecord;
    use WritesProjects;

    protected string $name = 'update_task';

    protected string $description = 'Rename a task, mark it done or open, or change its estimated hours. Only the fields you pass change.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()->format('uuid')->required(),
            'expected_version' => $this->versionArgument($schema),
            'name' => $schema->string()->min(1)->max(255),
            'is_done' => $schema->boolean(),
            'estimated_hours' => $schema->number()->min(0)->max(596523)->nullable()->description('Estimated hours, or null for none.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'task_id' => ['required', 'string', 'uuid'],
            'expected_version' => $this->versionRule(),
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'is_done' => ['sometimes', 'boolean:strict'],
            'estimated_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:596523'],
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $task = $this->findTask($context, $input['task_id']);
        [$confirmWith, $guard] = $this->bindToRecord(Task::class, $task['id'], $input, $context, 'task');
        $changes = array_intersect_key($input, array_flip(['name', 'is_done']));
        if (array_key_exists('estimated_hours', $input)) {
            $changes['estimated_time'] = $this->seconds($input['estimated_hours']);
        }
        if ($changes === []) {
            throw new ToolError('Pass at least one field to change.');
        }
        // The API needs the name on every update
        $body = ['name' => $task['name'], ...$changes];

        return new PlannedChange('Change a task', 'PUT', $context->path('tasks/'.$task['id']), $body,
            Present::task($task), Present::task([...$task, ...$changes]), $this->presentTask(), [], $confirmWith, $guard);
    }
}
