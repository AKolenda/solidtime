<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\Tools\Concerns\BindsToPreviewedVersion;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteTimeEntry extends WriteTool
{
    use BindsToPreviewedVersion;
    use FindsRecords;
    use WritesTimeEntries;

    protected string $name = 'delete_time_entry';

    protected string $description = 'Delete a time entry. This cannot be undone.';

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'time_entry_id' => $schema->string()->format('uuid')->required(),
            'expected_version' => $this->versionArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            'time_entry_id' => ['required', 'string', 'uuid'],
            'expected_version' => $this->versionRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        [$entry, $etag] = $this->findVersionedTimeEntry($context, $input['time_entry_id']);

        return new PlannedChange('Delete a time entry', 'DELETE', $context->path('time-entries/'.$entry['id']), [],
            $this->entryPreview([], $context, $entry), null, fn (): array => ['deleted' => $entry['id']],
            $this->versionHeaders($input), $etag === null ? [] : ['expected_version' => $etag]);
    }
}
