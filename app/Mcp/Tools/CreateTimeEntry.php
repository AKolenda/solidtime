<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\CreatesIdempotently;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive(false)]
class CreateTimeEntry extends WriteTool
{
    use CreatesIdempotently;
    use FindsRecords;
    use WritesTimeEntries;

    protected string $name = 'create_time_entry';

    protected string $description = 'Add a finished time entry with a start and an end. Entries of one person may not overlap.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            ...$this->entryArguments($schema),
            'start' => $schema->string()->format('date-time')->description('Start (ISO 8601 with offset).')->required(),
            'end' => $schema->string()->format('date-time')->description('End (ISO 8601 with offset).')->required(),
            'request_id' => $this->requestIdArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            ...$this->entryRules(),
            'start' => ['required', 'string', 'date'],
            'end' => ['required', 'string', 'date'],
            'request_id' => $this->requestIdRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        $body = $this->newEntryBody($input, $context, $this->utc($input['start']), $this->utc($input['end']));
        if (strtotime((string) $body['end']) < strtotime((string) $body['start'])) {
            throw new ToolError('The end must not be before the start.');
        }

        return new PlannedChange('Add a time entry', 'POST', $context->path('time-entries'), $body, null, $this->entryPreview($body, $context),
            $this->presentEntry($context), $this->idempotencyHeaders($input['request_id'] ?? null));
    }
}
