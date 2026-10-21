<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TimeEntryAggregationType;
use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\FiltersTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SummarizeTime extends McpTool
{
    use FiltersTimeEntries;

    protected string $name = 'summarize_time';

    protected string $description = 'Total tracked hours, grouped by member ("user"), project, task, client, day, week, month, year, billable, tag, type or description, optionally with a second grouping. Takes the same filters as list_time_entries. Use it for "hours by person this week", "hours per task on a project" or "daily hours".';

    protected function arguments(JsonSchema $schema): array
    {
        $groups = array_column(TimeEntryAggregationType::cases(), 'value');

        return [
            'group' => $schema->string()->enum($groups)->description('First grouping; "user" groups by member.')->required(),
            'sub_group' => $schema->string()->enum($groups)->description('Optional second grouping inside each first group.'),
            ...$this->filterArguments($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            'group' => ['required', Rule::enum(TimeEntryAggregationType::class)],
            'sub_group' => ['sometimes', Rule::enum(TimeEntryAggregationType::class), 'different:group'],
            ...$this->filterRules(),
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $query = ['group' => $input['group'], ...$this->filterQuery($input)];
        if (isset($input['sub_group'])) {
            $query['sub_group'] = $input['sub_group'];
        }
        $data = $context->api->send('GET', $context->path('time-entries/aggregate'), $query)['data'] ?? [];

        return Response::json([
            'total_hours' => Present::hours($data['seconds'] ?? 0),
            'grouped_by' => $data['grouped_type'] ?? null,
            'groups' => $this->groups($data['grouped_data'] ?? null, $data['grouped_type'] ?? null, $context),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $groups
     * @return array<int, array<string, mixed>>|null
     */
    private function groups(?array $groups, ?string $type, ToolContext $context): ?array
    {
        if ($groups === null) {
            return null;
        }
        $names = $this->names($type, array_values(array_filter(array_column($groups, 'key'), 'is_string')), $context);

        return array_map(fn (array $group): array => array_filter([
            'key' => $group['key'] ?? null,
            'name' => $names[$group['key'] ?? ''] ?? null,
            'hours' => Present::hours($group['seconds'] ?? 0),
            'grouped_by' => $group['grouped_type'] ?? null,
            'groups' => $this->groups($group['grouped_data'] ?? null, $group['grouped_type'] ?? null, $context),
        ], fn (mixed $value): bool => $value !== null), $groups);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function names(?string $type, array $keys, ToolContext $context): array
    {
        $names = match ($type) {
            'project' => array_map(fn (array $project): string => $project['name'], $context->visibleProjects()),
            'task' => $context->visibleTaskNames(),
            'client' => $context->clientNames(),
            'user' => array_map(fn (array $member): string => $member['name'], $context->memberNames()),
            default => [],
        };

        return array_intersect_key($names, array_flip($keys));
    }
}
