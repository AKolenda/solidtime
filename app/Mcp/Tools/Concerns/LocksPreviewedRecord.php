<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Http\Resources\V1\Project\ProjectResource;
use App\Http\Resources\V1\Task\TaskResource;
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
     * @param  Closure(): mixed  $visible  refuses a record the user may not see
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: Closure(): void}
     */
    protected function bindToRecord(string $class, string $id, array $input, ToolContext $context, string $what, Closure $visible): array
    {
        $visible();
        $query = fn () => $class::query()->whereBelongsTo($context->organization, 'organization')->whereKey($id);
        // The preview and its version come from one read of the record, so the version is of what the preview shows
        $model = $query()->first() ?? throw new ToolError(ucfirst($what).' not found, or not visible to you.', 404);
        $record = $model instanceof Project ? (new ProjectResource($model, false))->toArray(request()) : (new TaskResource($model))->toArray(request());
        $current = $this->recordVersion($model);
        if (($input['confirm'] ?? null) === true) {
            if (! isset($input['expected_version'])) {
                throw new ToolError("Confirm with the expected_version from the preview, so only the previewed state of the {$what} is changed.");
            }
            // The change is worked out from the record as it is now, which must be the one previewed
            if ($current !== $input['expected_version']) {
                throw new ToolError("The {$what} changed since the preview. Preview the change again.", 412);
            }
        }

        return [$record, ['expected_version' => $current], function () use ($query, $current, $what): void {
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
