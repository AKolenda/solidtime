<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PlannedChange;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\BindsToPreviewedVersion;
use App\Mcp\Tools\Concerns\FindsRecords;
use App\Mcp\Tools\Concerns\WritesTimeEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive(false)]
#[IsIdempotent]
class StopTimer extends WriteTool
{
    use BindsToPreviewedVersion;
    use FindsRecords;
    use WritesTimeEntries;

    protected string $name = 'stop_timer';

    protected string $description = "Stop a running timer. Without time_entry_id it stops your own running timer. Stopping someone else's timer needs permission to change everyone's time.";

    protected function changeArguments(JsonSchema $schema): array
    {
        return [
            'time_entry_id' => $schema->string()->format('uuid')->description('The running entry to stop. Defaults to your own running timer.'),
            'end' => $schema->string()->format('date-time')->description('When it stopped (ISO 8601 with offset). Defaults to the moment of confirmation.'),
            'expected_version' => $this->versionArgument($schema),
        ];
    }

    protected function rules(): array
    {
        return [
            'time_entry_id' => ['sometimes', 'string', 'uuid'],
            'end' => ['sometimes', 'string', 'date'],
            'expected_version' => $this->versionRule(),
        ];
    }

    protected function plan(array $input, ToolContext $context): PlannedChange
    {
        // Without an id the preview finds the running timer; the confirmation must then name the
        // timer that was previewed, so a timer started in between is never stopped by mistake.
        if (! isset($input['time_entry_id']) && ($input['confirm'] ?? null) === true) {
            throw new ToolError('Confirm with the time_entry_id from the preview, so the timer the user approved is the one stopped.');
        }
        $id = $input['time_entry_id'] ?? $this->runningTimerId($context);
        if ($id === null) {
            throw new ToolError('You have no running timer in this organization.', 404);
        }
        [$entry, $etag] = $this->findVersionedTimeEntry($context, $id);
        if ($entry['end'] !== null) {
            throw new ToolError('That time entry is not running.', 409);
        }
        $body = ['end' => $this->utc($input['end'] ?? now()->toIso8601String())];
        if (strtotime($body['end']) < strtotime((string) $entry['start'])) {
            throw new ToolError('The end must not be before the timer started.');
        }

        // Without an end the timer stops on confirmation, so the preview shows no fixed time
        $after = isset($input['end']) ? $this->entryPreview($body, $context, $entry) : [...$this->entryPreview([], $context, $entry), 'running' => false];

        return new PlannedChange(isset($input['end']) ? 'Stop the running timer' : 'Stop the running timer when the change is confirmed', 'PUT', $context->path('time-entries/'.$entry['id']), $body,
            $this->entryPreview([], $context, $entry), $after, $this->presentEntry($context),
            $this->versionHeaders($input), [...(isset($input['time_entry_id']) ? [] : ['time_entry_id' => $entry['id']]), ...($etag === null ? [] : ['expected_version' => $etag])],
            approvedBody: isset($input['end']) ? $body : ['end' => null]);
    }
}
