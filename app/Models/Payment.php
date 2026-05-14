<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Payment extends Model
{
    use Auditable, HasFactory;

    /**
     * 审计日志排除的字段
     */
    protected array $auditExcludeFields = ['updated_at', 'allocated_amount'];

    protected $fillable = [
        'payment_number',
        'store_id',
        'customer_id',
        'received_by',
        'amount',
        'allocated_amount',
        // 'payment_date' - removed, use created_at
        'payment_method',
        'reference_number',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        // 'payment_date' => 'datetime:Y-m-d H:i:s', - removed
    ];

    /**
     * 获取还款所属的门店
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * 获取还款所属的客户
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * 获取接收还款的用户
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * 获取还款的分配
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * 获取还款的附件
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * 获取还款的未分配金额
     */
    public function getUnallocatedAmountAttribute(): float
    {
        return $this->amount - $this->allocated_amount;
    }

    /**
     * 分配还款到指定账单
     *
     * 注意：此方法不再内部管理事务，调用方需要负责事务管理
     * 如果需要原子操作，请使用 allocateToInvoiceWithTransaction()
     *
     * @param  Invoice  $invoice  目标账单
     * @param  float  $amount  分配金额
     * @param  int  $allocatedBy  操作用户ID
     * @param  bool  $lockForUpdate  是否锁定记录防止并发问题
     */
    public function allocateToInvoice(Invoice $invoice, float $amount, int $allocatedBy, bool $lockForUpdate = true): PaymentAllocation
    {
        // 如果需要锁定，重新获取带锁的记录
        if ($lockForUpdate) {
            $this->refresh();
            $invoice->refresh();
        }

        // 创建还款分配记录
        $allocation = $this->allocations()->create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'allocated_by' => $allocatedBy,
        ]);

        // 更新还款已分配金额（saveQuietly 避免触发 Auditable update 事件，分配操作已由 PaymentAllocation create 日志覆盖）
        $this->allocated_amount = \App\Helpers\MoneyHelper::add($this->allocated_amount, $amount);
        $this->saveQuietly();

        // 更新账单已付金额并更新状态
        $invoice->paid_amount = \App\Helpers\MoneyHelper::add($invoice->paid_amount, $amount);
        $invoice->updateStatus();

        return $allocation;
    }

    /**
     * 分配还款到指定账单（带事务和锁）
     *
     * 此方法提供完整的事务管理和并发控制
     *
     * @param  Invoice  $invoice  目标账单
     * @param  float  $amount  分配金额
     * @param  int  $allocatedBy  操作用户ID
     */
    public function allocateToInvoiceWithTransaction(Invoice $invoice, float $amount, int $allocatedBy): PaymentAllocation
    {
        return DB::transaction(function () use ($invoice, $amount, $allocatedBy) {
            // 获取带锁的记录
            $lockedPayment = self::lockForUpdate()->find($this->id);
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if (! $lockedPayment || ! $lockedInvoice) {
                throw new \Exception('无法锁定还款或账单记录');
            }

            // 创建还款分配记录
            $allocation = $lockedPayment->allocations()->create([
                'invoice_id' => $lockedInvoice->id,
                'amount' => $amount,
                'allocated_by' => $allocatedBy,
            ]);

            // 更新还款已分配金额（saveQuietly 避免触发 Auditable update 事件）
            $lockedPayment->allocated_amount = \App\Helpers\MoneyHelper::add($lockedPayment->allocated_amount, $amount);
            $lockedPayment->saveQuietly();

            // 更新账单已付金额并更新状态
            $lockedInvoice->paid_amount = \App\Helpers\MoneyHelper::add($lockedInvoice->paid_amount, $amount);
            $lockedInvoice->updateStatus();

            // 同步当前实例的数据
            $this->allocated_amount = $lockedPayment->allocated_amount;

            return $allocation;
        });
    }

    /**
     * 检查是否完全分配
     */
    public function isFullyAllocated(): bool
    {
        return \App\Helpers\MoneyHelper::isZeroOrNegative($this->unallocated_amount);
    }

    /**
     * 检查是否有未分配金额
     */
    public function hasUnallocatedAmount(): bool
    {
        return \App\Helpers\MoneyHelper::isPositive($this->unallocated_amount);
    }

    /**
     * 撤销单个分配记录
     *
     * @param  PaymentAllocation  $allocation  要撤销的分配记录
     * @param  int  $revokedBy  操作用户ID
     *
     * @throws \Exception
     */
    public function revokeAllocation(PaymentAllocation $allocation, int $revokedBy): bool
    {
        // 验证分配记录属于此还款
        if ($allocation->payment_id !== $this->id) {
            throw new \Exception('分配记录不属于此还款');
        }

        return DB::transaction(function () use ($allocation, $revokedBy) {
            // 获取带锁的记录
            $lockedPayment = self::lockForUpdate()->find($this->id);
            $lockedInvoice = Invoice::lockForUpdate()->find($allocation->invoice_id);

            if (! $lockedPayment || ! $lockedInvoice) {
                throw new \Exception('无法锁定还款或账单记录');
            }

            $amount = $allocation->amount;

            // 更新还款已分配金额（saveQuietly 避免触发 Auditable update 事件）
            $lockedPayment->allocated_amount = \App\Helpers\MoneyHelper::toFloat(
                \App\Helpers\MoneyHelper::subtract($lockedPayment->allocated_amount, $amount)
            );
            $lockedPayment->saveQuietly();

            // 更新账单已付金额
            $lockedInvoice->paid_amount = \App\Helpers\MoneyHelper::toFloat(
                \App\Helpers\MoneyHelper::subtract($lockedInvoice->paid_amount, $amount)
            );
            $lockedInvoice->updateStatus();

            // 删除分配记录
            $allocation->delete();

            // 同步当前实例的数据
            $this->allocated_amount = $lockedPayment->allocated_amount;

            // 记录审计日志
            app(\App\Services\AuditLogService::class)->logCustom(
                'revoke_allocation',
                $this,
                "撤销分配记录: 账单 #{$lockedInvoice->id}, 金额 {$amount}",
                [
                    'payment_id' => $this->id,
                    'invoice_id' => $lockedInvoice->id,
                    'amount' => $amount,
                    'revoked_by' => $revokedBy,
                ]
            );

            return true;
        });
    }

    /**
     * 撤销所有分配记录
     *
     * @param  int  $revokedBy  操作用户ID
     * @return int 撤销的分配数量
     */
    public function revokeAllAllocations(int $revokedBy): int
    {
        $allocations = $this->allocations()->get();
        $count = 0;

        foreach ($allocations as $allocation) {
            $this->revokeAllocation($allocation, $revokedBy);
            $count++;
        }

        return $count;
    }

    /**
     * 获取还款的优惠减免记录
     */
    public function discounts()
    {
        return $this->hasMany(\App\Models\PaymentDiscount::class);
    }

    /**
     * 获取总优惠减免金额
     */
    public function getTotalDiscountAmountAttribute(): float
    {
        return $this->discounts()->sum('discount_amount');
    }

    /**
     * 创建优惠减免记录
     */
    public function createDiscount(Invoice $invoice, float $amount, string $type, string $reason, int $approvedBy): \App\Models\PaymentDiscount
    {
        return $this->discounts()->create([
            'invoice_id' => $invoice->id,
            'discount_amount' => $amount,
            'discount_type' => $type,
            'reason' => $reason,
            'approved_by' => $approvedBy,
        ]);
    }

    /**
     * 检查是否有优惠减免记录
     */
    public function hasDiscounts(): bool
    {
        return $this->discounts()->exists();
    }

    /**
     * 获取按类型分组的优惠减免统计
     */
    public function getDiscountSummaryAttribute(): array
    {
        $discounts = $this->discounts()->get();

        return [
            'total_amount' => $discounts->sum('discount_amount'),
            'by_type' => $discounts->groupBy('discount_type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('discount_amount'),
                ];
            })->toArray(),
        ];
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
