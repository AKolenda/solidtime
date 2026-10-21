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
class ListActiveTimers extends McpTool
{
    protected string $name = 'list_active_timers';

    protected string $description = "Timers running right now: who is running them, on what, and for how long. Shows everyone's timers when you may see everyone's time, otherwise only yours.";

    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function run(array $input, ToolContext $context): Response
    {
        $everyone = $context->can('time-entries:view:all');
        $query = ['active' => 'true', 'limit' => 500];
        if (! $everyone) {
            $query['member_id'] = $context->member->getKey();
        }
        $entries = $context->api->send('GET', $context->path('time-entries'), $query)['data'] ?? [];
        $names = Present::names($entries, $context);
        $now = now()->getTimestamp();

        return Response::json([
            'scope' => $everyone ? 'everyone' : 'yours',
            'timers' => array_map(fn (array $entry): array => [
                ...Present::timeEntry($entry, $names),
                'running_hours' => isset($entry['start']) ? round(max(0, $now - (int) strtotime((string) $entry['start'])) / 3600, 4) : null,
            ], $entries),
        ]);
    }
}
