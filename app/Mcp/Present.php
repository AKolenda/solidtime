<?php

declare(strict_types=1);

namespace App\Mcp;

/** Compact shapes for MCP answers: hours instead of seconds, names beside identifiers. */
class Present
{
    public static function hours(mixed $seconds): ?float
    {
        return is_numeric($seconds) ? round(((float) $seconds) / 3600, 4) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries  TimeEntryResource arrays
     * @return array<int, array<string, mixed>>
     */
    public static function timeEntries(array $entries, ToolContext $context): array
    {
        $names = self::names($entries, $context);

        return array_map(fn (array $entry): array => self::timeEntry($entry, $names), $entries);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{projects: array<string, string>, tasks: array<string, string>, members: array<string, array{id: string, name: string}>}|null  $names
     * @return array<string, mixed>
     */
    public static function timeEntry(array $entry, ?array $names = null, ?ToolContext $context = null): array
    {
        $names ??= $context !== null ? self::names([$entry], $context) : ['projects' => [], 'tasks' => [], 'members' => []];
        $running = ($entry['end'] ?? null) === null;

        return [
            'id' => $entry['id'] ?? null,
            'member_id' => $names['members'][$entry['user_id'] ?? '']['id'] ?? null,
            'member' => $names['members'][$entry['user_id'] ?? '']['name'] ?? null,
            'start' => $entry['start'] ?? null,
            'end' => $entry['end'] ?? null,
            'running' => $running,
            'hours' => $running ? null : self::hours($entry['duration'] ?? null),
            'description' => $entry['description'] ?? '',
            'project_id' => $entry['project_id'] ?? null,
            'project' => $names['projects'][$entry['project_id'] ?? ''] ?? null,
            'task_id' => $entry['task_id'] ?? null,
            'task' => $names['tasks'][$entry['task_id'] ?? ''] ?? null,
            'tags' => $entry['tags'] ?? [],
            'billable' => $entry['billable'] ?? null,
            'type' => $entry['type'] ?? null,
        ];
    }

    /**
     * Names of the projects, tasks and members the entries refer to, as far as the user may see
     * them. Members are keyed by user ID, which is what a time entry carries.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{projects: array<string, string>, tasks: array<string, string>, members: array<string, array{id: string, name: string}>}
     */
    public static function names(array $entries, ToolContext $context): array
    {
        $projectIds = array_values(array_unique(array_filter(array_column($entries, 'project_id'), 'is_string')));
        $projects = array_intersect_key($context->visibleProjects(), array_flip($projectIds));
        $taskIds = array_values(array_unique(array_filter(array_column($entries, 'task_id'), 'is_string')));
        $tasks = $taskIds === [] ? [] : ($context->visibleTaskQuery()?->whereIn('id', $taskIds)->pluck('name', 'id')->all() ?? []);

        return [
            'projects' => array_map(fn (array $project): string => $project['name'], $projects),
            'tasks' => $tasks,
            'members' => $context->memberNames(),
        ];
    }

    /**
     * @param  array<string, mixed>  $project  ProjectResource array
     * @return array<string, mixed>
     */
    public static function project(array $project): array
    {
        $estimated = self::hours($project['estimated_time'] ?? null);
        $spent = self::hours($project['spent_time'] ?? null) ?? 0.0;

        return [
            'id' => $project['id'] ?? null,
            'name' => $project['name'] ?? null,
            'client_id' => $project['client_id'] ?? null,
            'color' => $project['color'] ?? null,
            'is_archived' => $project['is_archived'] ?? null,
            'is_billable' => $project['is_billable'] ?? null,
            'is_public' => $project['is_public'] ?? null,
            'estimated_hours' => $estimated,
            'actual_hours' => $spent,
            'remaining_hours' => $estimated === null ? null : round($estimated - $spent, 4),
            'percent_of_estimate' => $estimated ? round($spent / $estimated * 100, 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $task  TaskResource array
     * @return array<string, mixed>
     */
    public static function task(array $task): array
    {
        $estimated = self::hours($task['estimated_time'] ?? null);
        $spent = self::hours($task['spent_time'] ?? null) ?? 0.0;

        return [
            'id' => $task['id'] ?? null,
            'name' => $task['name'] ?? null,
            'project_id' => $task['project_id'] ?? null,
            'is_done' => $task['is_done'] ?? null,
            'estimated_hours' => $estimated,
            'actual_hours' => $spent,
            'percent_of_estimate' => $estimated ? round($spent / $estimated * 100, 1) : null,
        ];
    }
}
