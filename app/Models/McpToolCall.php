<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One MCP tool call: who called which tool with what (redacted) arguments, and how it ended.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $organization_id
 * @property string|null $client_id
 * @property string|null $token_id
 * @property string $tool
 * @property string $outcome started, ok, preview, error, denied or rate_limited
 * @property array<string, mixed> $arguments
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class McpToolCall extends Model
{
    use HasUuids;
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'duration_ms' => 'integer',
    ];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(1, (int) config('mcp.audit_retention_days'))));
    }
}
