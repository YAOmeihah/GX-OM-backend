<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PaymentDiscount;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use App\Services\AuditLogService;

class PaymentDiscountService
{
    /**
     * 检测还款与总欠款的差额
     */
    public function detectPaymentGap(Payment $payment): array
    {
        $customer = $payment->customer;
        $storeId = $payment->store_id;

        // 获取该客户在该门店的所有未付清账单，预加载 discounts 避免 N+1
        $unpaidInvoices = $customer->invoices()
            ->where('store_id', $storeId)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->with('discounts')
            ->get();

        // 计算总欠款（考虑已有的优惠减免）
        $totalDebt = $unpaidInvoices->sum(function ($invoice) {
            return $invoice->actual_remaining_amount;
        });

        $paymentAmount = $payment->amount;
        $gap = \App\Helpers\MoneyHelper::toFloat(
            \App\Helpers\MoneyHelper::subtract($totalDebt, $paymentAmount)
        );

        $hasGap = \App\Helpers\MoneyHelper::isPositive($gap);
        $maxDiscountAmount = config('payment.max_discount_amount', 1000);

        return [
            'has_gap' => $hasGap,
            'gap_amount' => $hasGap ? $gap : 0,
            'total_debt' => $totalDebt,
            'payment_amount' => $paymentAmount,
            'unpaid_invoices' => $unpaidInvoices,
            'can_apply_discount' => $hasGap && \App\Helpers\MoneyHelper::isLessThanOrEqual($gap, $maxDiscountAmount),
            'suggested_discount_type' => $this->suggestDiscountType($gap),
        ];
    }

    /**
     * 处理优惠抹零场景
     */
    public function processDiscountScenario(
        Payment $payment,
        array $discountData,
        int $approvedBy
    ): array {
        DB::beginTransaction();

        try {
            $results = [];
            $gapInfo = $this->detectPaymentGap($payment);

            if (!$gapInfo['has_gap']) {
                throw new \Exception('没有检测到需要处理的差额');
            }

            // 验证折扣数据
            $this->validateDiscountData($discountData, $gapInfo);

            // 先进行正常的还款分配
            $allocationResults = $this->allocatePaymentToInvoices($payment, $gapInfo['unpaid_invoices']);

            // 然后处理优惠减免
            $discountResults = $this->createDiscountRecords($payment, $discountData, $approvedBy);

            // 更新账单状态
            $this->updateInvoiceStatuses($gapInfo['unpaid_invoices']);

            DB::commit();

            return [
                'success' => true,
                'allocations' => $allocationResults,
                'discounts' => $discountResults,
                'gap_info' => $gapInfo,
                'message' => '优惠抹零处理完成'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 自动分配还款到账单
     */
    private function allocatePaymentToInvoices(Payment $payment, Collection $invoices): array
    {
        $remainingAmount = $payment->amount;
        $allocations = [];

        // 按账单日期排序，优先分配较早的账单
        $sortedInvoices = $invoices->sortBy('created_at');

        foreach ($sortedInvoices as $invoice) {
            // 使用 MoneyHelper 进行精确比较
            if (\App\Helpers\MoneyHelper::isZeroOrNegative($remainingAmount)) {
                break;
            }

            $invoiceRemaining = $invoice->actual_remaining_amount;
            $allocateAmount = \App\Helpers\MoneyHelper::toFloat(
                \App\Helpers\MoneyHelper::min($remainingAmount, $invoiceRemaining)
            );

            if (\App\Helpers\MoneyHelper::isPositive($allocateAmount)) {
                // 注意：此方法在外部事务中调用，不再需要内部事务
                $allocation = $payment->allocateToInvoice(
                    $invoice,
                    $allocateAmount,
                    $payment->received_by
                );

                $allocations[] = [
                    'allocation' => $allocation,
                    'invoice' => $invoice,
                    'amount' => $allocateAmount
                ];

                $remainingAmount = \App\Helpers\MoneyHelper::toFloat(
                    \App\Helpers\MoneyHelper::subtract($remainingAmount, $allocateAmount)
                );
            }
        }

        return $allocations;
    }

    /**
     * 创建优惠减免记录
     */
    private function createDiscountRecords(Payment $payment, array $discountData, int $approvedBy): array
    {
        $discounts = [];

        foreach ($discountData as $data) {
            $invoice = Invoice::findOrFail($data['invoice_id']);

            $discount = $payment->createDiscount(
                $invoice,
                $data['amount'],
                $data['type'] ?? PaymentDiscount::TYPE_DISCOUNT,
                $data['reason'] ?? '优惠抹零',
                $approvedBy
            );

            $discounts[] = [
                'discount' => $discount,
                'invoice' => $invoice,
                'amount' => $data['amount']
            ];
        }

        return $discounts;
    }

    /**
     * 更新账单状态
     */
    private function updateInvoiceStatuses(Collection $invoices): void
    {
        foreach ($invoices as $invoice) {
            $invoice->refresh(); // 刷新数据以获取最新的付款和折扣信息
            $invoice->updateStatusWithDiscounts();
        }
    }

    /**
     * 验证折扣数据
     */
    private function validateDiscountData(array $discountData, array $gapInfo): void
    {
        $totalDiscountAmount = collect($discountData)->sum('amount');

        // 使用 MoneyHelper 进行精确比较
        if (\App\Helpers\MoneyHelper::isGreaterThan($totalDiscountAmount, $gapInfo['gap_amount'])) {
            throw new \Exception('折扣总金额不能超过差额金额');
        }

        foreach ($discountData as $data) {
            if (!isset($data['invoice_id']) || !isset($data['amount'])) {
                throw new \Exception('折扣数据格式不正确');
            }

            if (\App\Helpers\MoneyHelper::isZeroOrNegative($data['amount'])) {
                throw new \Exception('折扣金额必须大于0');
            }

            // 验证账单是否存在且属于正确的客户和门店
            $invoice = Invoice::find($data['invoice_id']);
            if (!$invoice) {
                throw new \Exception("账单 {$data['invoice_id']} 不存在");
            }

            if (!$gapInfo['unpaid_invoices']->contains('id', $invoice->id)) {
                throw new \Exception("账单 {$invoice->invoice_number} 不在待处理列表中");
            }
        }
    }

    /**
     * 建议折扣类型
     */
    private function suggestDiscountType(float $amount): string
    {
        if ($amount <= 10) {
            return PaymentDiscount::TYPE_DISCOUNT; // 小额差异建议为折扣
        } elseif ($amount <= 100) {
            return PaymentDiscount::TYPE_PROMOTION; // 中等金额建议为促销优惠
        } else {
            return PaymentDiscount::TYPE_WRITE_OFF; // 大额差异建议为坏账核销
        }
    }

    /**
     * 获取优惠减免统计
     */
    public function getDiscountStatistics(?int $storeId = null, array $dateRange = null): array
    {
        $query = PaymentDiscount::query()
            ->join('payments', 'payment_discounts.payment_id', '=', 'payments.id');

        if ($storeId !== null) {
            $query->where('payments.store_id', $storeId);
        }

        if ($dateRange) {
            $query->whereBetween('payment_discounts.created_at', $dateRange);
        }

        $discounts = $query->get();

        return [
            'total_count' => $discounts->count(),
            'total_amount' => $discounts->sum('discount_amount'),
            'by_type' => $discounts->groupBy('discount_type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('discount_amount'),
                ];
            })->toArray(),
            'average_amount' => $discounts->count() > 0 ? $discounts->avg('discount_amount') : 0,
        ];
    }

    /**
     * 检查用户是否有权限进行优惠减免
     */
    public function canApproveDiscount(int $userId, int $storeId, string $discountType = null, float $amount = null): bool
    {
        $user = \App\Models\User::find($userId);

        if (!$user) {
            return false;
        }

        // 系统管理员可以进行任何优惠减免
        if ($user->hasRole('admin')) {
            return true;
        }

        // 检查用户是否属于该门店
        if (!$user->stores()->where('store_id', $storeId)->exists()) {
            return false;
        }

        // 如果指定了折扣类型，检查特定类型的权限
        if ($discountType) {
            $discountConfig = config("payment.discount_types.{$discountType}");
            if (!$discountConfig) {
                return false;
            }

            $allowedRoles = $discountConfig['approval_roles'] ?? [];
            $hasRole = false;

            foreach ($allowedRoles as $role) {
                if ($user->hasRole($role)) {
                    $hasRole = true;
                    break;
                }
            }

            if (!$hasRole) {
                return false;
            }

            // 检查金额限制
            if ($amount !== null) {
                return \App\Http\Middleware\CheckDiscountPermission::checkDiscountAmount($user, $discountType, $amount);
            }
        } else {
            // 通用权限检查
            if ($user->hasRole('store_owner')) {
                return true;
            }

            // 店员在配置允许的情况下可以进行小额优惠减免
            if ($user->hasRole('store_staff')) {
                $staffCanDiscount = config('payment.discount_types.discount.approval_roles', []);
                return in_array('store_staff', $staffCanDiscount);
            }
        }

        return true;
    }

    /**
     * 验证优惠减免权限和金额
     */
    public function validateDiscountPermissions(int $userId, int $storeId, array $discountData): array
    {
        $user = \App\Models\User::find($userId);
        $errors = [];

        if (!$user) {
            return ['用户不存在'];
        }

        foreach ($discountData as $index => $data) {
            $discountType = $data['type'] ?? 'discount';
            $amount = $data['amount'] ?? 0;

            // 检查类型权限
            if (!$this->canApproveDiscount($userId, $storeId, $discountType, $amount)) {
                $errors[] = "第" . ($index + 1) . "项：您没有权限进行{$discountType}类型的优惠减免";
                continue;
            }

            // 检查是否需要额外审批
            if (\App\Http\Middleware\CheckDiscountPermission::requiresApproval($discountType, $amount)) {
                // 这里可以添加审批流程的逻辑
                // 目前简化处理，只有管理员和店长可以进行需要审批的操作
                if (!$user->hasRole(['admin', 'store_owner'])) {
                    $errors[] = "第" . ($index + 1) . "项：该优惠减免需要管理员或店长审批";
                }
            }
        }

        return $errors;
    }

    /**
     * 记录优惠减免操作日志
     */
    public function logDiscountOperation(Payment $payment, array $discountData, int $userId, string $action = 'create'): void
    {
        if (!config('payment.audit.log_all_discounts', true)) {
            return;
        }

        $totalAmount = collect($discountData)->sum('amount');

        app(AuditLogService::class)->logCustom(
            \App\Models\AuditLog::ACTION_DISCOUNT,
            $payment,
            "优惠减免操作({$action})：还款 {$payment->payment_number}，共 {$totalAmount} 元，涉及 " . count($discountData) . " 笔",
            [
                'discount_action' => $action,
                'discount_count'  => count($discountData),
                'total_discount_amount' => $totalAmount,
                'discount_details' => $discountData,
            ]
        );
    }
}
