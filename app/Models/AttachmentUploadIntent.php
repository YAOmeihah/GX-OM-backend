<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttachmentUploadIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
        'uploaded_by',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
