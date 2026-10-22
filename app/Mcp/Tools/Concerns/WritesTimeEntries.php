<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Fields shared by the tools that create or change time entries. */
trait WritesTimeEntries
{
    use FiltersTimeEntries;

    /**
     * @return array<string, mixed>
     */
    protected function entryArguments(JsonSchema $schema): array
    {
        return [
            'member_id' => $schema->string()->format('uuid')->description('Whose time it is (member ID from list_members). Defaults to you; another member needs permission to change everyone\'s time.'),
            'project_id' => $schema->string()->format('uuid')->description('Project the time is for.'),
            'task_id' => $schema->string()->format('uuid')->description('Task of that project.'),
            'description' => $schema->string()->max(5000)->description('What was done.'),
            'billable' => $schema->boolean()->description('Billable time. Defaults to whether the project is billable.'),
            'tags' => $schema->array()->items($schema->string()->format('uuid'))->max(20)->description('Tag IDs from list_tags.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function entryRules(): array
    {
        return [
            'member_id' => ['sometimes', 'string', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'task_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'billable' => ['sometimes', 'boolean:strict'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function newEntryBody(array $input, ToolContext $context, string $start, ?string $end): array
    {
        $projectId = $input['project_id'] ?? null;
        // A task belongs to one project, which the API requires alongside it: a task alone brings its project
        if (isset($input['task_id'])) {
            $projectId = $this->checkTaskProject($context, $input['task_id'], $projectId);
        }
        $billable = $input['billable'] ?? ($context->canUseBillableRates() && (bool) ($context->visibleProjects()[$projectId ?? '']['is_billable'] ?? false));

        return [
            'member_id' => $input['member_id'] ?? $context->member->getKey(),
            'project_id' => $projectId,
            'task_id' => $input['task_id'] ?? null,
            'start' => $start,
            'end' => $end,
            'billable' => $billable,
            'description' => $input['description'] ?? null,
            'tags' => $input['tags'] ?? [],
        ];
    }

    /**
     * The project of a visible task, refusing one that belongs to another project than the one given.
     */
    protected function checkTaskProject(ToolContext $context, string $taskId, ?string $projectId): string
    {
        $taskProject = $context->visibleTaskQuery()?->whereKey($taskId)->value('project_id');
        if (! is_string($taskProject)) {
            throw new ToolError('Task not found, or not visible to you.', 404);
        }
        if ($projectId !== null && $projectId !== $taskProject) {
            throw new ToolError('That task belongs to another project.');
        }

        return $taskProject;
    }

    /**
     * A billable value the preview worked out from the project, so the confirmation keeps it if the project changes meanwhile.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function billableBinding(array $input, array $body): array
    {
        return array_key_exists('billable', $input) ? [] : ['billable' => $body['billable']];
    }

    /**
     * How a request body reads in a preview: the entry as it will look.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $base  the current entry for an update
     * @return array<string, mixed>
     */
    protected function entryPreview(array $body, ToolContext $context, array $base = []): array
    {
        $entry = [...$base, ...$body];
        if (isset($body['member_id'])) {
            $entry['user_id'] = $context->organization->members()->whereKey($body['member_id'])->value('user_id')
                ?? throw new ToolError('Member not found in this organization.', 404);
        }
        $start = isset($entry['start']) ? strtotime((string) $entry['start']) : false;
        $end = isset($entry['end']) ? strtotime((string) $entry['end']) : false;
        $entry['duration'] = $start !== false && $end !== false ? max(0, $end - $start) : null;

        return Present::timeEntry($entry, null, $context);
    }

    protected function presentEntry(ToolContext $context): Closure
    {
        return fn (mixed $data): ?array => is_array($data) ? Present::timeEntry($data, null, $context) : null;
    }
}
