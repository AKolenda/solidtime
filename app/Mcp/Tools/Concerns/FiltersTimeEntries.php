<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Throwable;

/** The time-entry filters shared by list_time_entries and summarize_time. */
trait FiltersTimeEntries
{
    private const ID_LISTS = ['member_ids', 'project_ids', 'task_ids', 'client_ids', 'tag_ids'];

    /**
     * @return array<string, mixed>
     */
    protected function filterArguments(JsonSchema $schema): array
    {
        $ids = fn (string $what): mixed => $schema->array()->items($schema->string()->format('uuid'))->max(100)->description("Only entries of these {$what}.");

        return [
            'member_ids' => $ids('members (member IDs from list_members)'),
            'project_ids' => $ids('projects'),
            'task_ids' => $ids('tasks'),
            'client_ids' => $ids('clients'),
            'tag_ids' => $ids('tags'),
            'start' => $schema->string()->format('date-time')->description('Only entries starting at or after this time (ISO 8601 with offset, e.g. 2026-10-01T00:00:00-06:00).'),
            'end' => $schema->string()->format('date-time')->description('Only entries starting before this time (ISO 8601 with offset).'),
            'billable' => $schema->boolean()->description('Only billable (true) or non-billable (false) entries.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        $rules = [
            'start' => ['sometimes', 'string', 'date'],
            'end' => ['sometimes', 'string', 'date'],
            'billable' => ['sometimes', 'boolean:strict'],
        ];
        foreach (self::ID_LISTS as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:100'];
            $rules[$key.'.*'] = ['string', 'uuid'];
        }

        return $rules;
    }

    /**
     * The filters as API query parameters.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function filterQuery(array $input): array
    {
        $query = [];
        foreach (self::ID_LISTS as $key) {
            if (($input[$key] ?? []) !== []) {
                $query[$key] = array_values(array_unique($input[$key]));
            }
        }
        // The API lets a member without access to everyone's time read their own only through member_id
        if (count($query['member_ids'] ?? []) === 1) {
            $query['member_id'] = $query['member_ids'][0];
            unset($query['member_ids']);
        }
        foreach (['start', 'end'] as $key) {
            if (isset($input[$key])) {
                $query[$key] = $this->utc($input[$key]);
            }
        }
        if (isset($query['start'], $query['end']) && $query['start'] >= $query['end']) {
            throw new ToolError('start must be before end.');
        }
        if (isset($input['billable'])) {
            $query['billable'] = $input['billable'] ? 'true' : 'false';
        }

        return $query;
    }

    protected function utc(string $time): string
    {
        try {
            return Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            throw new ToolError('Use ISO 8601 times, for example 2026-10-01T08:00:00-06:00.');
        }
    }
}
