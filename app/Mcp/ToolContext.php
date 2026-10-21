<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Service\PermissionStore;

/** The signed-in user, the organization a tool call works in, and the API it goes through. */
class ToolContext
{
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
}
