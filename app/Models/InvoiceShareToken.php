<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceShareToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'invoice_ids',
        'customer_id',
        'store_id',
        'created_by',
        'expires_at',
        'type',
    ];

    const TYPE_FIXED = 'fixed';
    const TYPE_DYNAMIC = 'dynamic';

    protected $casts = [
        'invoice_ids' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * 生成新的分享令牌
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(16)); // 32 字符的随机令牌
    }

    /**
     * 检查令牌是否有效（未过期）
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    /**
     * 获取关联的客户
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * 获取关联的门店
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * 获取创建者
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 获取访问日志
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(InvoiceShareTokenLog::class, 'token_id');
    }

    /**
     * 记录一次访问
     */
    public function logAccess(?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->accessLogs()->create([
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'accessed_at' => now(),
        ]);
    }

    /**
     * 获取关联的账单
     */
    public function getInvoices()
    {
        return Invoice::with(['items', 'store:id,name,phone', 'customer:id,name,phone'])
            ->whereIn('id', $this->invoice_ids)
            ->get();
    }
}
