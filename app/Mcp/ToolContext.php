<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Service\PermissionStore;

/**
 * The signed-in user, the organization a tool call works in, and the API it goes through.
 * Names and records come from the API's own lists, so a tool shows only what the user may see.
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
        return $this->projects ??= collect($this->listOrNothing('projects', ['archived' => 'all']))->keyBy('id')->all();
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

        return $this->tasks[$projectId] ??= collect($this->listOrNothing('tasks', ['project_id' => $projectId, 'done' => 'all']))->keyBy('id')->all();
    }

    /**
     * Names of every task the user may see, by ID.
     *
     * @return array<string, string>
     */
    public function visibleTaskNames(): array
    {
        return $this->allTasks ??= collect($this->listOrNothing('tasks', ['done' => 'all']))->pluck('name', 'id')->all();
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
            foreach ($this->listOrNothing('members') as $member) {
                $members[$member['user_id']] = ['id' => $member['id'], 'name' => $member['name']];
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
        return $this->clients ??= collect($this->listOrNothing('clients', ['archived' => 'all']))->pluck('name', 'id')->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function listOrNothing(string $path, array $query = []): array
    {
        try {
            return $this->api->all($this->path($path), $query);
        } catch (ToolError $error) {
            if (in_array($error->status, [403, 404], true)) {
                return [];
            }
            throw $error;
        }
    }
}
