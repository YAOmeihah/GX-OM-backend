<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'discount_amount',
        'discount_type',
        'reason',
        'approved_by',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
    ];

    /**
     * 折扣类型常量
     */
    const TYPE_WRITE_OFF = 'write_off';    // 坏账核销
    const TYPE_DISCOUNT = 'discount';      // 折扣
    const TYPE_PROMOTION = 'promotion';    // 促销优惠

    /**
     * 获取所有可用的折扣类型
     */
    public static function getDiscountTypes(): array
    {
        return [
            self::TYPE_WRITE_OFF => '坏账核销',
            self::TYPE_DISCOUNT => '折扣',
            self::TYPE_PROMOTION => '促销优惠',
        ];
    }

    /**
     * 获取折扣类型的中文描述
     */
    public function getDiscountTypeNameAttribute(): string
    {
        $types = self::getDiscountTypes();
        return $types[$this->discount_type] ?? '未知类型';
    }

    /**
     * 获取关联的还款记录
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * 获取关联的账单
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * 获取审批人
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * 检查是否为坏账核销
     */
    public function isWriteOff(): bool
    {
        return $this->discount_type === self::TYPE_WRITE_OFF;
    }

    /**
     * 检查是否为折扣
     */
    public function isDiscount(): bool
    {
        return $this->discount_type === self::TYPE_DISCOUNT;
    }

    /**
     * 检查是否为促销优惠
     */
    public function isPromotion(): bool
    {
        return $this->discount_type === self::TYPE_PROMOTION;
    }

    /**
     * 获取格式化的折扣金额
     */
    public function getFormattedDiscountAmountAttribute(): string
    {
        return number_format($this->discount_amount, 2, '.', '');
    }

    /**
     * 作用域：按折扣类型筛选
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('discount_type', $type);
    }

    /**
     * 作用域：按还款筛选
     */
    public function scopeByPayment($query, int $paymentId)
    {
        return $query->where('payment_id', $paymentId);
    }

    /**
     * 作用域：按账单筛选
     */
    public function scopeByInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    /**
     * 作用域：按审批人筛选
     */
    public function scopeByApprover($query, int $userId)
    {
        return $query->where('approved_by', $userId);
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
