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
        return $this->findVersionedTimeEntry($context, $id)[0];
    }

    /**
     * The entry and its ETag, which an update sends back as If-Match so the API refuses it if
     * the entry changed in between.
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    protected function findVersionedTimeEntry(ToolContext $context, string $id): array
    {
        try {
            [$body, $headers] = $context->api->sendWithHeaders('GET', $context->path('time-entries/'.$id));

            return [$body['data'] ?? [], $headers->get('ETag')];
        } catch (ToolError $error) {
            throw new ToolError('Time entry not found, or not visible to you.', $error->status === 401 ? 401 : 404);
        }
    }

    /**
     * The id of the user's running timer in this organization, if there is one.
     */
    protected function runningTimerId(ToolContext $context): ?string
    {
        /** @var string|null */
        return TimeEntry::query()->whereBelongsTo($context->organization, 'organization')
            ->where('user_id', $context->user->getKey())->whereNull('end')->latest('start')->value('id');
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
     * A project from the list the user may see (the show endpoint needs projects:view:all).
     *
     * @return array<string, mixed> ProjectResource array
     */
    protected function findProject(ToolContext $context, string $id): array
    {
        return $context->visibleProjects()[$id] ?? throw new ToolError('Project not found, or not visible to you.', 404);
    }

    /**
     * @return array<string, mixed> TaskResource-like array
     */
    protected function findTask(ToolContext $context, string $id): array
    {
        // Only the project is looked up here; the task itself comes from the list the user may see
        $projectId = Task::query()->whereBelongsTo($context->organization, 'organization')->whereKey($id)->value('project_id');

        return (is_string($projectId) ? $context->visibleTasks($projectId)[$id] ?? null : null)
            ?? throw new ToolError('Task not found, or not visible to you.', 404);
    }
}
