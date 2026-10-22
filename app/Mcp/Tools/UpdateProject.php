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
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateProject extends WriteTool
{
    use FindsRecords;
    use LocksPreviewedRecord;
    use WritesProjects;

    protected string $name = 'update_project';

    protected string $description = 'Change a project: name, color, client, billable, public, archived or estimated hours. Only the fields you pass change. Changing the client moves its time entries to the new client.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->format('uuid')->required(),
            'expected_version' => $this->versionArgument($schema),
            'name' => $schema->string()->min(1)->max(255),
            ...$this->projectArguments($schema),
            'is_archived' => $schema->boolean()->description('Archive (true) or restore (false) the project.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'project_id' => ['required', 'string', 'uuid'],
            'expected_version' => $this->versionRule(),
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            ...$this->projectRules(),
            'is_archived' => ['sometimes', 'boolean:strict'],
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        [$project, $confirmWith, $guard] = $this->bindToRecord(Project::class, $input['project_id'], $input, $context, 'project',
            fn (): array => $this->findProject($context, $input['project_id']));
        $changes = array_intersect_key($input, array_flip(['name', 'color', 'client_id', 'is_billable', 'is_public', 'is_archived']));
        if (array_key_exists('estimated_hours', $input)) {
            $changes['estimated_time'] = $this->seconds($input['estimated_hours']);
        }
        if ($changes === []) {
            throw new ToolError('Pass at least one field to change.');
        }
        // The API needs the name, color, billable flag and client on every update
        $body = [
            'name' => $project['name'],
            'color' => $project['color'],
            'is_billable' => $project['is_billable'],
            'client_id' => $project['client_id'],
            ...$changes,
        ];

        return new PlannedChange('Change a project', 'PUT', $context->path('projects/'.$project['id']), $body,
            Present::project($project), Present::project([...$project, ...$changes]), $this->presentProject(), [], $confirmWith, $guard);
    }
}
