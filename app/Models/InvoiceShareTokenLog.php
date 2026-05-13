<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceShareTokenLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'token_id',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    /**
     * 获取关联的分享令牌
     */
    public function shareToken(): BelongsTo
    {
        return $this->belongsTo(InvoiceShareToken::class, 'token_id');
    }
}
