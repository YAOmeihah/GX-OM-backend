<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory, Auditable;

    /**
     * 审计日志排除的字段
     */
    protected array $auditExcludeFields = ['updated_at'];

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'description',
        'is_active',
        'wechat_pay_code_data',
        'alipay_code_data',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * 获取门店的用户
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * 获取门店的店长
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->whereHas('roles', function ($q) {
                $q->where('slug', 'store_owner');
            })
            ->withTimestamps();
    }

    /**
     * 获取门店的店员
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->whereHas('roles', function ($q) {
                $q->where('slug', 'store_staff');
            })
            ->withTimestamps();
    }

    /**
     * 获取门店的账单
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * 获取门店的还款
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}