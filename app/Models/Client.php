<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CustomAuditable;
use App\Models\Concerns\HasUuids;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $name
 * @property string $organization_id
 * @property-read bool $is_archived
 * @property Carbon|null $archived_at
 * @property Carbon|null $reopened_at
 * @property-read bool $is_closed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 *
 * @method static ClientFactory factory()
 */
class Client extends Model implements AuditableContract
{
    use CustomAuditable;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'name' => 'string',
        'archived_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public const int CLOSED_AFTER_MONTHS = 12;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    /**
     * @param  Builder<Client>  $builder
     * @return Builder<Client>
     */
    public function scopeVisibleByEmployee(Builder $builder, User $user): Builder
    {
        return $builder->whereHas('projects', function (Builder $builder) use ($user): Builder {
            /** @var Builder<Project> $builder */
            return $builder->visibleByEmployee($user);
        });
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isArchived(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => isset($attributes['archived_at']),
        );
    }

    /**
     * A client closes automatically once no project has been created for it in the last
     * CLOSED_AFTER_MONTHS. Creating or reopening the client restarts that clock.
     * Archiving is manual and takes precedence.
     *
     * @return Attribute<bool, never>
     */
    protected function isClosed(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                if ($this->is_archived) {
                    return false;
                }
                $latestProjectAt = array_key_exists('projects_max_created_at', $this->attributes)
                    ? $this->attributes['projects_max_created_at']
                    : $this->projects()->max('created_at');
                $lastActivityAt = collect([
                    $this->created_at,
                    $this->reopened_at,
                    $latestProjectAt !== null ? Carbon::parse($latestProjectAt) : null,
                ])->filter()->max();

                return $lastActivityAt !== null
                    && $lastActivityAt->lt(Carbon::now()->subMonths(self::CLOSED_AFTER_MONTHS));
            },
        );
    }
}
