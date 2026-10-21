<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Http\Resources\V1\TimeEntry\TimeEntryResource;
use App\Mcp\ToolContext;
use App\Mcp\ToolError;
use App\Models\Task;
use App\Models\TimeEntry;

/** Current state for previews, read only as far as the user may see it. */
trait FindsRecords
{
    /**
     * @return array<string, mixed> TimeEntryResource array
     */
    protected function findTimeEntry(ToolContext $context, string $id): array
    {
        try {
            return $context->api->send('GET', $context->path('time-entries/'.$id))['data'] ?? [];
        } catch (ToolError $error) {
            throw new ToolError('Time entry not found, or not visible to you.', $error->status === 401 ? 401 : 404);
        }
    }

    /**
     * The user's own running timer in this organization, if there is one.
     *
     * @return array<string, mixed>|null TimeEntryResource array
     */
    protected function runningTimer(ToolContext $context, ?string $userId = null): ?array
    {
        /** @var TimeEntry|null $entry */
        $entry = TimeEntry::query()->where('user_id', $userId ?? $context->user->getKey())->whereNull('end')->latest('start')->first();

        return $entry === null ? null : (new TimeEntryResource($entry))->toArray(request());
    }

    /**
     * Through the API, so project visibility applies.
     *
     * @return array<string, mixed> ProjectResource array
     */
    protected function findProject(ToolContext $context, string $id): array
    {
        return $context->api->send('GET', $context->path('projects/'.$id))['data'] ?? [];
    }

    /**
     * @return array<string, mixed> TaskResource-like array
     */
    protected function findTask(ToolContext $context, string $id): array
    {
        /** @var Task|null $task */
        $task = Task::query()->whereBelongsTo($context->organization, 'organization')->find($id);
        if ($task === null) {
            throw new ToolError('Task not found, or not visible to you.', 404);
        }
        try {
            // Its project must be visible to the user
            $this->findProject($context, $task->project_id);
        } catch (ToolError) {
            throw new ToolError('Task not found, or not visible to you.', 404);
        }

        return ['id' => $task->id, 'name' => $task->name, 'project_id' => $task->project_id, 'is_done' => $task->is_done,
            'estimated_time' => $task->estimated_time, 'spent_time' => $task->spent_time];
    }
}
