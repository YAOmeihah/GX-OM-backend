<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use Auditable, HasFactory;

    /**
     * 审计日志排除的字段
     */
    protected array $auditExcludeFields = ['updated_at'];

    protected $fillable = [
        'store_id',
        'name',
        'phone',
        'email',
        'address',
        'id_card',
        'remarks',
    ];

    /**
     * 获取客户所属门店
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // 注意：不再使用 $appends 自动追加 total_debt
    // total_debt 由 CustomerController 根据门店权限动态计算并设置

    /**
     * 获取客户的账单
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * 该客户在各个门店下的聚合统计数据（物化视图缓存）。
     */
    public function storeStats()
    {
        return $this->hasMany(CustomerStoreStat::class);
    }

    /**
     * 获取客户的还款
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * 获取客户的未付清账单
     */
    public function unpaidInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->whereIn('status', ['unpaid', 'partially_paid', 'overdue']);
    }

    /**
     * 计算客户的总欠款金额（不包含优惠减免）
     * 注意：此方法不包含门店权限过滤，仅用于系统内部计算
     * 对外API应使用带权限过滤的方法
     */
    /**
     * 计算客户的总欠款金额（不包含优惠减免）
     * 如果已手动设置该属性（如Controller中），则返回设置值，否则计算全局欠款
     */
    public function getTotalDebtAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        return $this->unpaidInvoices()->sum(DB::raw('amount - paid_amount'));
    }

    /**
     * 计算客户的实际总欠款金额（包含优惠减免）
     * 注意：此方法不包含门店权限过滤，仅用于系统内部计算
     * 对外API应使用带权限过滤的方法
     */
    /**
     * 计算客户的实际总欠款金额（包含优惠减免）
     * 如果已手动设置该属性，则返回设置值，否则计算全局欠款
     */
    public function getActualTotalDebtAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }
        $unpaidInvoices = $this->unpaidInvoices()->get();

        return $unpaidInvoices->sum(function ($invoice) {
            return $invoice->actual_remaining_amount;
        });
    }

    /**
     * 计算客户在指定门店的总欠款金额（不包含优惠减免）
     */
    public function getTotalDebtForStores(array $storeIds): float
    {
        return $this->invoices()
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->sum(DB::raw('amount - paid_amount'));
    }

    /**
     * 计算客户在指定门店的实际总欠款金额（包含优惠减免）
     */
    public function getActualTotalDebtForStores(array $storeIds): float
    {
        $unpaidInvoices = $this->invoices()
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->get();

        return $unpaidInvoices->sum(function ($invoice) {
            return $invoice->actual_remaining_amount;
        });
    }

    /**
     * 获取客户在指定门店的欠款信息
     */
    public function getStoreDebtInfo(int $storeId): array
    {
        $storeInvoices = $this->invoices()->where('store_id', $storeId)->get();
        $unpaidInvoices = $storeInvoices->whereIn('status', ['unpaid', 'partially_paid', 'overdue']);

        $totalAmount = $storeInvoices->sum('amount');
        $paidAmount = $storeInvoices->sum('paid_amount');
        $discountAmount = $storeInvoices->sum('total_discount_amount');
        // 计算实际欠款（考虑优惠减免）
        $actualDebt = $unpaidInvoices->sum(function ($invoice) {
            return max(0, $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount);
        });

        return [
            'total_invoices' => $storeInvoices->count(),
            'unpaid_invoices' => $unpaidInvoices->count(),
            'total_amount' => (float) $totalAmount,
            'paid_amount' => (float) $paidAmount,
            'discount_amount' => (float) $discountAmount,
            'traditional_debt' => (float) ($totalAmount - $paidAmount),
            'actual_debt' => (float) $actualDebt,
            'discount_rate' => $totalAmount > 0 ? round(($discountAmount / $totalAmount) * 100, 2) : 0.0,
            'store_count' => 1, // 单门店查询，固定为1
        ];
    }

    /**
     * 获取客户的优惠减免统计
     */
    public function getDiscountSummaryAttribute(): array
    {
        $discounts = \App\Models\PaymentDiscount::whereHas('payment', function ($query) {
            $query->where('customer_id', $this->id);
        })->get();

        return [
            'total_count' => $discounts->count(),
            'total_amount' => $discounts->sum('discount_amount'),
            'by_type' => $discounts->groupBy('discount_type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('discount_amount'),
                ];
            })->toArray(),
            'by_store' => $discounts->groupBy('payment.store_id')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('discount_amount'),
                ];
            })->toArray(),
        ];
    }

    /**
     * 检查客户是否有优惠减免记录
     */
    public function hasDiscounts(): bool
    {
        return \App\Models\PaymentDiscount::whereHas('payment', function ($query) {
            $query->where('customer_id', $this->id);
        })->exists();
    }

    /**
     * 获取客户的还款和优惠减免历史
     */
    public function getPaymentHistoryWithDiscounts(?int $storeId = null): array
    {
        $paymentsQuery = $this->payments()->with(['discounts', 'allocations.invoice']);

        if ($storeId) {
            $paymentsQuery->where('store_id', $storeId);
        }

        $payments = $paymentsQuery->orderBy('created_at', 'desc')->get();

        return $payments->map(function ($payment) {
            return [
                'payment' => $payment,
                'has_discounts' => $payment->hasDiscounts(),
                'discount_summary' => $payment->discount_summary,
                'total_allocated' => $payment->allocations->sum('amount'),
                'total_discounted' => $payment->discounts->sum('discount_amount'),
            ];
        })->toArray();
    }

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
