<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemUpdateRun extends Model
{
    protected $fillable = [
        'actor_user_id',
        'tag',
        'version',
        'status',
        'step',
        'metadata',
        'log_lines',
        'backup_path',
        'package_path',
        'package_sha256',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'log_lines' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
