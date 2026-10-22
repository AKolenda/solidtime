<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Http\Resources\V1\Project\ProjectResource;
use App\Http\Resources\V1\Task\TaskResource;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Service\PermissionStore;
use Illuminate\Database\Eloquent\Builder;

/**
 * The signed-in user, the organization a tool call works in, and the API it goes through.
 * Projects, tasks, clients and members are read with the same permission checks and
 * visibility scopes as the API's index endpoints, so a tool shows only what the user may see,
 * without paging through every list.
 */
class ToolContext
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $projects = null;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $tasks = [];

    /** @var array<string, string>|null */
    private ?array $allTasks = null;

    /** @var array<string, array{id: string, name: string}>|null */
    private ?array $members = null;

    /** @var array<string, string>|null */
    private ?array $clients = null;

    public function __construct(
        public readonly User $user,
        public readonly Organization $organization,
        public readonly Member $member,
        public readonly InternalApi $api,
    ) {}

    /** API path below /api/v1/organizations/{organization}/ */
    public function path(string $path = ''): string
    {
        return 'organizations/'.$this->organization->getKey().($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    public function can(string $permission): bool
    {
        return app(PermissionStore::class)->has($this->organization, $permission);
    }

    /**
     * Projects the user may see, archived ones included, by ID.
     *
     * @return array<string, array<string, mixed>> ProjectResource arrays
     */
    public function visibleProjects(): array
    {
        if ($this->projects !== null) {
            return $this->projects;
        }
        if (! $this->can('projects:view')) {
            return $this->projects = [];
        }
        // As ProjectController::index
        $query = Project::query()->whereBelongsTo($this->organization, 'organization');
        if (! $this->can('projects:view:all')) {
            $query->visibleByEmployee($this->user);
        }

        return $this->projects = $query->orderBy('created_at', 'desc')->orderBy('id')->get()
            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => (new ProjectResource($project, false))->toArray(request())])
            ->all();
    }

    /**
     * Tasks the user may see, as TaskController::index; null without permission to see tasks.
     *
     * @return Builder<Task>|null
     */
    public function visibleTaskQuery(): ?Builder
    {
        if (! $this->can('tasks:view')) {
            return null;
        }
        $query = Task::query()->whereBelongsTo($this->organization, 'organization');
        if (! $this->can('tasks:view:all')) {
            $query->visibleByEmployee($this->user);
        }

        return $query;
    }

    /**
     * Tasks of one project, when the user may see that project and its tasks.
     *
     * @return array<string, array<string, mixed>> TaskResource arrays by ID
     */
    public function visibleTasks(string $projectId): array
    {
        if (! isset($this->visibleProjects()[$projectId])) {
            return [];
        }

        return $this->tasks[$projectId] ??= ($this->visibleTaskQuery()?->where('project_id', $projectId)->orderBy('created_at', 'desc')->orderBy('id')->get() ?? collect())
            ->mapWithKeys(fn (Task $task): array => [$task->getKey() => (new TaskResource($task))->toArray(request())])
            ->all();
    }

    /**
     * Names of every task the user may see, by ID.
     *
     * @return array<string, string>
     */
    public function visibleTaskNames(): array
    {
        return $this->allTasks ??= $this->visibleTaskQuery()?->pluck('name', 'id')->all() ?? [];
    }

    /**
     * Members by user ID. Without permission to see members, only the user.
     *
     * @return array<string, array{id: string, name: string}>
     */
    public function memberNames(): array
    {
        if ($this->members !== null) {
            return $this->members;
        }
        $members = [$this->user->getKey() => ['id' => $this->member->getKey(), 'name' => $this->user->name]];
        if ($this->can('members:view')) {
            foreach (Member::query()->whereBelongsTo($this->organization, 'organization')->with('user')->get() as $member) {
                $members[$member->user_id] = ['id' => $member->getKey(), 'name' => $member->user->name];
            }
        }

        return $this->members = $members;
    }

    /**
     * Names of the clients the user may see, by ID.
     *
     * @return array<string, string>
     */
    public function clientNames(): array
    {
        if ($this->clients !== null) {
            return $this->clients;
        }
        if (! $this->can('clients:view')) {
            return $this->clients = [];
        }
        // As ClientController::index
        $query = Client::query()->whereBelongsTo($this->organization, 'organization');
        if (! $this->can('clients:view:all')) {
            $query->visibleByEmployee($this->user);
        }

        return $this->clients = $query->pluck('name', 'id')->all();
    }
}
