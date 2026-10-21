<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesProjects;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteProject extends WriteTool
{
    use FindsRecords;
    use WritesProjects;

    protected string $name = 'delete_project';

    protected string $description = 'Delete an unused project. SolidTime refuses while the project has tasks or time entries; archive it with update_project instead. This cannot be undone.';

    protected function changeArguments(JsonSchema $schema): array
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

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $project = $this->findProject($context, $input['project_id']);
        $entries = TimeEntry::query()->whereBelongsTo($context->organization, 'organization')->where('project_id', $project['id'])->count();
        $tasks = Task::query()->whereBelongsTo($context->organization, 'organization')->where('project_id', $project['id'])->count();
        $inUse = $entries > 0 || $tasks > 0 ? " (it has {$tasks} tasks and {$entries} time entries, so SolidTime will refuse; archive it instead)" : '';

        return new PlannedChange('Delete a project'.$inUse,
            'DELETE', $context->path('projects/'.$project['id']), [], Present::project($project), null, fn (): array => ['deleted' => $project['id']]);
    }
}
