<?php

namespace App\Models;

use App\Helpers\MoneyHelper;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, HasFactory;

    /**
     * 审计日志排除的字段（这些字段变更不记录）
     */
    protected array $auditExcludeFields = ['updated_at'];

    protected $fillable = [
        'invoice_number',
        'store_id',
        'customer_id',
        'created_by',
        'amount',
        'paid_amount',
        // 'invoice_date' - removed, use created_at
        'due_date',
        'status',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        // 'invoice_date' => 'datetime:Y-m-d H:i:s', - removed
        'due_date' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 获取账单所属的门店
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * 获取账单所属的客户
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * 获取创建账单的用户
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 获取账单的还款分配
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * 获取账单的明细项目
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * 获取账单的附件
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * 获取账单的剩余未付金额（不含优惠减免）
     */
    public function getRemainingAmountAttribute(): float
    {
        return \App\Helpers\MoneyHelper::toFloat(
            \App\Helpers\MoneyHelper::subtract($this->amount, $this->paid_amount)
        );
    }

    /**
     * 更新账单状态
     *
     * 此方法会自动检测是否有优惠减免，并据此计算正确的状态
     * 使用 MoneyHelper 确保金额比较的精度
     */
    public function updateStatus(): void
    {
        // 计算总已付金额（包含优惠减免）
        $totalPaidAndDiscounted = \App\Helpers\MoneyHelper::add(
            $this->paid_amount,
            $this->total_discount_amount
        );

        // 使用精确比较判断状态
        if (\App\Helpers\MoneyHelper::isGreaterThanOrEqual($totalPaidAndDiscounted, $this->amount)) {
            $this->status = 'paid';
        } elseif (
            \App\Helpers\MoneyHelper::isPositive($this->paid_amount) ||
            \App\Helpers\MoneyHelper::isPositive($this->total_discount_amount)
        ) {
            $this->status = 'partially_paid';
        } elseif ($this->due_date && $this->due_date->isPast()) {
            $this->status = 'overdue';
        } else {
            $this->status = 'unpaid';
        }

        $this->saveQuietly(); // 使用 saveQuietly 避免触发事件循环

        // 由于 saveQuietly() 绕过了 Laravel 事件系统（Observer 无法感知），
        // 需要在此处直接触发统计更新，确保 paid_amount/status 变化后
        // 客户的 customer_store_stats 数据保持同步
        try {
            app(\App\Services\CustomerStatsService::class)
                ->syncCustomerStoreStats($this->customer_id, $this->store_id);
        } catch (\Exception $e) {
            \Log::error('updateStatus: 同步客户统计失败', [
                'invoice_id' => $this->id,
                'customer_id' => $this->customer_id,
                'store_id' => $this->store_id,
                'error' => $e->getMessage(),
            ]);
        }

    }

    /**
     * 根据明细项目自动计算总金额
     */
    public function calculateTotalAmount(): void
    {
        $totalAmount = $this->items()->sum('subtotal');

        // 只有当计算出的总金额与当前金额不同时才更新
        if ($totalAmount != $this->amount) {
            $this->amount = $totalAmount;
            $this->save();

            // 重新计算状态
            $this->updateStatus();
        }
    }

    /**
     * 检查账单是否有明细项目
     */
    public function hasItems(): bool
    {
        return $this->items()->exists();
    }

    /**
     * 获取账单的优惠减免记录
     */
    public function discounts()
    {
        return $this->hasMany(\App\Models\PaymentDiscount::class);
    }

    /**
     * 获取总优惠减免金额
     *
     * 优化：优先使用已加载的关系数据，避免 N+1 查询
     */
    public function getTotalDiscountAmountAttribute(): float
    {
        // 如果关系已经加载，直接计算
        if ($this->relationLoaded('discounts')) {
            return (float) $this->discounts->sum('discount_amount');
        }

        // 否则查询数据库
        return (float) $this->discounts()->sum('discount_amount');
    }

    /**
     * 获取包含优惠减免的实际剩余金额
     */
    public function getActualRemainingAmountAttribute(): float
    {
        $remainingAmount = \App\Helpers\MoneyHelper::subtract($this->amount, $this->paid_amount);
        $actualRemaining = \App\Helpers\MoneyHelper::subtract($remainingAmount, $this->total_discount_amount);

        return \App\Helpers\MoneyHelper::toFloat(
            \App\Helpers\MoneyHelper::max($actualRemaining, 0)
        );
    }

    /**
     * 检查账单是否完全结清（包括优惠减免）
     */
    public function isFullySettled(): bool
    {
        return $this->actual_remaining_amount <= 0;
    }

    /**
     * 更新账单状态（考虑优惠减免）
     *
     * @deprecated 请使用 updateStatus()，该方法现在已自动考虑优惠减免
     */
    public function updateStatusWithDiscounts(): void
    {
        // 直接调用统一的 updateStatus 方法
        $this->updateStatus();
    }

    /**
     * 检查账单是否已有付款、分配或优惠等财务活动
     */
    public function hasFinancialActivity(): bool
    {
        return MoneyHelper::isPositive((float) $this->paid_amount)
            || $this->paymentAllocations()->exists()
            || $this->discounts()->exists();
    }

    /**
     * 检查是否有优惠减免记录
     */
    public function hasDiscounts(): bool
    {
        return $this->discounts()->exists();
    }

    /**
     * Prepare a date for array / JSON serialization.
     * 使用本地时区格式，避免前端时区转换问题
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
