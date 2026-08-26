<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseBackupRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'validated' => 'boolean',
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
