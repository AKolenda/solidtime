<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Present;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\FiltersTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListTimeEntries extends McpTool
{
    use FiltersTimeEntries;

    protected string $name = 'list_time_entries';

    protected string $description = "List time entries, newest first, filtered by member, project, task, client, tag, time range and billable. Without member_ids this needs permission to see everyone's time; pass your own member ID to see only yours. Hours are per entry; use summarize_time for totals.";

    protected function arguments(JsonSchema $schema): array
    {
        return [
            ...$this->filterArguments($schema),
            'running' => $schema->boolean()->description('Only running timers (true) or only finished entries (false).'),
            'limit' => $schema->integer()->min(1)->max(500)->description('At most this many entries (default 100, max 500).'),
            'offset' => $schema->integer()->min(0)->description('Skip this many entries, for the next page.'),
        ];
    }

    protected function rules(): array
    {
        return [
            ...$this->filterRules(),
            'running' => ['sometimes', 'boolean:strict'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $limit = $input['limit'] ?? 100;
        $offset = $input['offset'] ?? 0;
        $query = [...$this->filterQuery($input), 'limit' => $limit, 'offset' => $offset];
        if (isset($input['running'])) {
            $query['active'] = $input['running'] ? 'true' : 'false';
        }
        $body = $context->api->send('GET', $context->path('time-entries'), $query);
        $entries = Present::timeEntries($body['data'] ?? [], $context);
        $total = $body['meta']['total'] ?? null;

        return Response::json([
            'entries' => $entries,
            'count' => count($entries),
            'total' => $total,
            'hours_in_this_page' => round(array_sum(array_map(fn (array $entry): float => $entry['hours'] ?? 0.0, $entries)), 4),
            'next_offset' => is_int($total) && $offset + count($entries) < $total ? $offset + count($entries) : null,
        ]);
    }
}
