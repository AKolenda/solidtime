<?php

declare(strict_types=1);

namespace App\Mcp;

use Closure;

/** One API call a write tool will make, with what the user sees before approving it. */
class PlannedChange
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $before  the record as it is now, null for a creation
     * @param  array<string, mixed>|null  $after  the record as it will be, null for a deletion
     * @param  Closure(mixed): mixed  $present  shapes the API's answer for the client
     * @param  array<string, mixed>  $confirmWith  arguments the confirming call must add, naming the previewed record
     * @param  (Closure(): void)|null  $guard  run in the change's transaction just before it, refusing it if the record changed
     * @param  array<string, mixed>|null  $approvedBody  the body as the user approves it, when the confirmation fills in part of it (such as the time)
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $method,
        public readonly string $path,
        public readonly array $body,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly Closure $present,
        public readonly array $headers = [],
        public readonly array $confirmWith = [],
        public readonly ?Closure $guard = null,
        public readonly ?array $approvedBody = null,
    ) {}
}
