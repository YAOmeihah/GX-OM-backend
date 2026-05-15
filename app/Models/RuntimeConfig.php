<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeConfig extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted:array',
    ];
}
