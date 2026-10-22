<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTimeEntry extends WriteTool
{
    use FindsRecords;
    use WritesTimeEntries;

    protected string $name = 'update_time_entry';

    protected string $description = 'Change a time entry: times, project, task, description, billable, tags or member. Only the fields you pass change; pass project_id or task_id as null to clear them.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'time_entry_id' => $schema->string()->format('uuid')->required(),
            ...$this->entryArguments($schema),
            'project_id' => $schema->string()->format('uuid')->nullable()->description('New project, or null to clear it (which also clears the task).'),
            'task_id' => $schema->string()->format('uuid')->nullable()->description('New task of the project, or null to clear it.'),
            'start' => $schema->string()->format('date-time')->description('New start (ISO 8601 with offset).'),
            'end' => $schema->string()->format('date-time')->description('New end (ISO 8601 with offset).'),
        ];
    }

    protected function rules(): array
    {
        return [
            'time_entry_id' => ['required', 'string', 'uuid'],
            ...$this->entryRules(),
            'start' => ['sometimes', 'string', 'date'],
            'end' => ['sometimes', 'string', 'date'],
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        [$entry, $etag] = $this->findVersionedTimeEntry($context, $input['time_entry_id']);
        $body = array_intersect_key($input, array_flip(['member_id', 'project_id', 'task_id', 'description', 'billable', 'tags']));
        // The API checks a task against the project sent with it: a new task alone keeps the entry's project
        if (isset($body['task_id']) && ! array_key_exists('project_id', $body)) {
            if (($entry['project_id'] ?? null) === null) {
                throw new ToolError('A task needs its project; pass project_id too.');
            }
            $body['project_id'] = $entry['project_id'];
        }
        foreach (['start', 'end'] as $key) {
            if (isset($input[$key])) {
                $body[$key] = $this->utc($input[$key]);
            }
        }
        if (array_key_exists('project_id', $body) && $body['project_id'] === null && ! array_key_exists('task_id', $body)) {
            $body['task_id'] = null;
        }
        if ($body === []) {
            throw new ToolError('Pass at least one field to change.');
        }

        return new PlannedChange('Change a time entry', 'PUT', $context->path('time-entries/'.$entry['id']), $body,
            $this->entryPreview([], $context, $entry), $this->entryPreview($body, $context, $entry), $this->presentEntry($context),
            $etag === null ? [] : ['If-Match' => $etag]);
    }
}
