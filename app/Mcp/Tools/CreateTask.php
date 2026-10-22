<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\CreatesIdempotently;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesProjects;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive(false)]
class CreateTask extends WriteTool
{
    use CreatesIdempotently;
    use FindsRecords;
    use WritesProjects;

    protected string $name = 'create_task';

    protected string $description = 'Create a task (an operation such as Turning or Milling) on a project, optionally with estimated hours.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->format('uuid')->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'estimated_hours' => $schema->number()->min(0)->max(1000000)->nullable()->description('Estimated hours for this task.'),
            'request_id' => $this->requestIdArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            'project_id' => ['required', 'string', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'estimated_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'request_id' => $this->requestIdRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $project = $this->findProject($context, $input['project_id']);
        $body = ['name' => $input['name'], 'project_id' => $project['id'], 'estimated_time' => $this->seconds($input['estimated_hours'] ?? null)];

        return new PlannedChange('Create a task on '.$project['name'], 'POST', $context->path('tasks'), $body, null,
            Present::task($body), $this->presentTask(), $this->idempotencyHeaders($input['request_id'] ?? null));
    }
}
