<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseBackupSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enabled',
        'time',
        'timezone',
        'retention_days',
        'subdirectory',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'retention_days' => 'integer',
    ];
}
