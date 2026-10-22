<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\CreatesIdempotently;
use App\Mcp\Tools\Concerns\WritesProjects;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive(false)]
class CreateProject extends WriteTool
{
    use CreatesIdempotently;
    use WritesProjects;

    protected string $name = 'create_project';

    protected string $description = 'Create a project. Project names are unique per client.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->min(1)->max(255)->required(),
            ...$this->projectArguments($schema),
            'request_id' => $this->requestIdArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            ...$this->projectRules(),
            'request_id' => $this->requestIdRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $body = [
            'name' => $input['name'],
            'color' => $input['color'] ?? $this->defaultColor($input['name']),
            'client_id' => $input['client_id'] ?? null,
            'is_billable' => $input['is_billable'] ?? false,
            'is_public' => $input['is_public'] ?? false,
            'estimated_time' => $this->seconds($input['estimated_hours'] ?? null),
        ];

        return new PlannedChange('Create a project', 'POST', $context->path('projects'), $body, null,
            Present::project($body), $this->presentProject(), $this->idempotencyHeaders($input['request_id'] ?? null));
    }
}
