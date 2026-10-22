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
class ListProjects extends McpTool
{
    protected string $name = 'list_projects';

    protected string $description = 'Projects you can see, with estimated hours against actual tracked hours, remaining hours and percent of estimate. Search filters by name (case-insensitive substring). Use get_project for its tasks.';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(255)->description('Only projects whose name contains this text.'),
            'archived' => $schema->string()->enum(['false', 'true', 'all'])->description('Active projects (default), archived ones, or all.'),
            'client_id' => $schema->string()->format('uuid')->description('Only projects of this client.'),
            'over_estimate' => $schema->boolean()->description('Only projects whose actual hours exceed their estimate.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'archived' => ['sometimes', 'string', 'in:false,true,all'],
            'client_id' => ['sometimes', 'string', 'uuid'],
            'over_estimate' => ['sometimes', 'boolean:strict'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $search = mb_strtolower(trim($input['search'] ?? ''));
        $archived = $input['archived'] ?? 'false';
        $projects = collect($context->visibleProjects())
            ->filter(fn (array $project): bool => $archived === 'all' || ($archived === 'true') === (bool) $project['is_archived'])
            ->map(fn (array $project): array => Present::project($project))
            ->when($search !== '', fn ($projects) => $projects->filter(fn (array $project): bool => str_contains(mb_strtolower((string) $project['name']), $search)))
            ->when(isset($input['client_id']), fn ($projects) => $projects->where('client_id', $input['client_id']))
            ->when(($input['over_estimate'] ?? false) === true, fn ($projects) => $projects->filter(fn (array $project): bool => $project['remaining_hours'] !== null && $project['remaining_hours'] < 0))
            ->values();

        return Response::json([
            'projects' => $projects->take(200)->all(),
            'count' => $projects->count(),
            'truncated' => $projects->count() > 200,
        ]);
    }
}
