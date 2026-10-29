<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

abstract class BaseResource extends JsonResource
{
    protected function formatDateTime(?Carbon $carbon): ?string
    {
        return $carbon?->toIso8601ZuluString();
    }

    /**
     * Start of the latest time entry, when the query loaded it with withMax('timeEntries', 'start')
     */
    protected function formatLastActivityAt(Model $model): ?string
    {
        $lastActivityAt = $model->getAttributes()['time_entries_max_start'] ?? null;

        return $lastActivityAt !== null ? $this->formatDateTime(Carbon::parse($lastActivityAt, 'UTC')) : null;
    }

    protected function formatDate(?Carbon $carbon): ?string
    {
        return $carbon?->format('Y-m-d');
    }
}
