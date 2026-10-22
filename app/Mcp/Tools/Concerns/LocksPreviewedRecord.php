<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Project;
use App\Models\Task;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A preview hands out the record's version; the confirming call sends it back, and the change
 * is made only while the record, locked for the change, is still in the state the user approved.
 */
trait LocksPreviewedRecord
{
    use BindsToPreviewedVersion;

    /**
     * The record as the preview shows it, what the preview hands out, and the check the
     * confirmation runs with the record locked.
     *
     * @param  class-string<Project|Task>  $class
     * @param  array<string, mixed>  $input
     * @param  Closure(): array<string, mixed>  $read  the record as the preview shows it
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: Closure(): void}
     */
    protected function bindToRecord(string $class, string $id, array $input, ToolContext $context, string $what, Closure $read): array
    {
        $query = fn () => $class::query()->whereBelongsTo($context->organization, 'organization')->whereKey($id);
        // The version read before and after what the preview shows, so it is the version of what it shows
        $current = $this->recordVersion($query()->first());
        $record = $read();
        if ($this->recordVersion($query()->first()) !== $current) {
            throw new ToolError("The {$what} changed while it was being read. Try again.", 409);
        }
        if (($input['confirm'] ?? null) === true) {
            if (! isset($input['expected_version'])) {
                throw new ToolError("Confirm with the expected_version from the preview, so only the previewed state of the {$what} is changed.");
            }
            // The change is worked out from the record as it is now, which must be the one previewed
            if ($current !== $input['expected_version']) {
                throw new ToolError("The {$what} changed since the preview. Preview the change again.", 412);
            }
        }

        return [$record, $current === null ? [] : ['expected_version' => $current], function () use ($query, $current, $what): void {
            if ($this->recordVersion($query()->lockForUpdate()->first()) !== $current) {
                throw new ToolError("The {$what} changed since the preview. Preview the change again.", 412);
            }
        }];
    }

    private function recordVersion(?Model $record): ?string
    {
        // The spent time follows the time entries, which recalculating it saves (and so touches), not the record
        return $record === null ? null : hash('sha256', json_encode(Arr::except($record->getAttributes(), ['spent_time', 'updated_at']), JSON_THROW_ON_ERROR));
    }
}
