<?php

namespace App\Services;

use App\Enums\PaymentAllocationStrategy;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoAllocationService
{
    /**
     * 自动分配还款到账单
     */
    public function autoAllocate(
        Payment $payment,
        PaymentAllocationStrategy $strategy = PaymentAllocationStrategy::OLDEST_FIRST
    ): array {
        // 使用 MoneyHelper 进行精确比较
        if (\App\Helpers\MoneyHelper::isZeroOrNegative($payment->unallocated_amount)) {
            return [];
        }

        // 获取客户的未付账单
        $unpaidInvoices = $this->getUnpaidInvoices($payment, $strategy);

        if ($unpaidInvoices->isEmpty()) {
            return [];
        }

        // 执行分配
        return $this->performAllocation($payment, $unpaidInvoices);
    }

    /**
     * 获取分配建议
     */
    public function getAllocationSuggestion(
        Payment $payment,
        PaymentAllocationStrategy $strategy = PaymentAllocationStrategy::OLDEST_FIRST
    ): array {
        $unpaidInvoices = $this->getUnpaidInvoices($payment, $strategy);

        if ($unpaidInvoices->isEmpty()) {
            return [
                'suggestions' => [],
                'allocations' => [],
                'total_debt' => 0,
                'excess_amount' => $payment->unallocated_amount,
                'can_fully_allocate' => false,
            ];
        }

        $suggestions = $this->calculateAllocationSuggestion($payment, $unpaidInvoices);
        $totalDebt = $unpaidInvoices->sum('actual_remaining_amount');
        $excessAmount = max(0, $payment->unallocated_amount - $totalDebt);

        return [
            'suggestions' => $suggestions,
            'allocations' => $suggestions,
            'total_debt' => $totalDebt,
            'excess_amount' => $excessAmount,
            'can_fully_allocate' => $payment->unallocated_amount <= $totalDebt,
            'strategy' => $strategy->value,
            'strategy_description' => $strategy->getDescription(),
        ];
    }

    /**
     * 检测超额还款
     */
    public function detectExcessPayment(Payment $payment): array
    {
        $totalDebt = Invoice::with('discounts')
            ->where('customer_id', $payment->customer_id)
            ->where('store_id', $payment->store_id)
            ->whereRaw('amount > paid_amount')
            ->get()
            ->sum('actual_remaining_amount');

        $isExcess = \App\Helpers\MoneyHelper::isGreaterThan($payment->amount, $totalDebt);
        $excessAmount = $isExcess
            ? \App\Helpers\MoneyHelper::toFloat(\App\Helpers\MoneyHelper::subtract($payment->amount, $totalDebt))
            : 0;

        return [
            'is_excess' => $isExcess,
            'excess_amount' => $excessAmount,
            'total_debt' => $totalDebt,
            'payment_amount' => $payment->amount,
            'recommendations' => $this->getExcessPaymentRecommendations($excessAmount),
        ];
    }

    /**
     * 获取客户的未付账单
     */
    private function getUnpaidInvoices(Payment $payment, PaymentAllocationStrategy $strategy): Collection
    {
        $query = Invoice::with('discounts')
            ->where('customer_id', $payment->customer_id)
            ->where('store_id', $payment->store_id)
            ->whereRaw('amount > paid_amount'); // 有未付余额的账单

        // 应用策略的查询条件
        foreach ($strategy->getWhereConditions() as $condition) {
            if (count($condition) === 3) {
                [$field, $operator, $value] = $condition;
                if ($operator === 'in') {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $operator, $value);
                }
            }
        }

        // 应用排序
        if ($strategy === PaymentAllocationStrategy::OVERDUE_FIRST) {
            $query->orderByRaw(
                "CASE WHEN status = 'overdue' OR due_date < ? THEN 0 ELSE 1 END",
                [now()->toDateString()]
            )
                ->orderBy('due_date')
                ->orderBy('created_at');
        } else {
            [$orderField, $orderDirection] = $strategy->getOrderBy();
            $query->orderBy($orderField, $orderDirection);
        }

        // 添加计算字段
        return $query->get()
            ->map(function ($invoice) {
                $invoice->remaining_amount = $invoice->actual_remaining_amount;

                return $invoice;
            })
            ->filter(fn ($invoice) => \App\Helpers\MoneyHelper::isPositive($invoice->actual_remaining_amount))
            ->values();
    }

    /**
     * 执行实际的分配操作
     */
    private function performAllocation(Payment $payment, Collection $unpaidInvoices): array
    {
        $remainingAmount = $payment->unallocated_amount;
        $allocations = [];

        DB::transaction(function () use ($payment, $unpaidInvoices, &$remainingAmount, &$allocations) {
            foreach ($unpaidInvoices as $invoice) {
                // 使用 MoneyHelper 进行精确比较
                if (\App\Helpers\MoneyHelper::isZeroOrNegative($remainingAmount)) {
                    break;
                }

                $invoiceRemaining = $invoice->actual_remaining_amount;
                $allocateAmount = \App\Helpers\MoneyHelper::toFloat(
                    \App\Helpers\MoneyHelper::min($remainingAmount, $invoiceRemaining)
                );

                if (\App\Helpers\MoneyHelper::isPositive($allocateAmount)) {
                    $allocation = $payment->allocateToInvoice(
                        $invoice,
                        $allocateAmount,
                        $payment->received_by
                    );

                    $allocations[] = [
                        'allocation' => $allocation,
                        'invoice' => $invoice,
                        'amount' => $allocateAmount,
                    ];

                    $remainingAmount = \App\Helpers\MoneyHelper::toFloat(
                        \App\Helpers\MoneyHelper::subtract($remainingAmount, $allocateAmount)
                    );
                }
            }
        });

        return $allocations;
    }

    /**
     * 计算分配建议（不执行实际分配）
     */
    private function calculateAllocationSuggestion(Payment $payment, Collection $unpaidInvoices): array
    {
        $remainingAmount = $payment->unallocated_amount;
        $suggestions = [];

        foreach ($unpaidInvoices as $invoice) {
            // 使用 MoneyHelper 进行精确比较
            if (\App\Helpers\MoneyHelper::isZeroOrNegative($remainingAmount)) {
                break;
            }

            $invoiceRemaining = $invoice->actual_remaining_amount;
            $allocateAmount = \App\Helpers\MoneyHelper::toFloat(
                \App\Helpers\MoneyHelper::min($remainingAmount, $invoiceRemaining)
            );

            if (\App\Helpers\MoneyHelper::isPositive($allocateAmount)) {
                $suggestions[] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_amount' => $invoice->amount,
                    'paid_amount' => $invoice->paid_amount,
                    'remaining_amount' => $invoiceRemaining,
                    'suggested_amount' => $allocateAmount,
                    'will_be_fully_paid' => \App\Helpers\MoneyHelper::isGreaterThanOrEqual($allocateAmount, $invoiceRemaining),
                    'created_at' => $invoice->created_at,
                    'due_date' => $invoice->due_date,
                    'status' => $invoice->status,
                ];

                $remainingAmount = \App\Helpers\MoneyHelper::toFloat(
                    \App\Helpers\MoneyHelper::subtract($remainingAmount, $allocateAmount)
                );
            }
        }

        return $suggestions;
    }

    /**
     * 获取超额还款处理建议
     */
    private function getExcessPaymentRecommendations(float $excessAmount): array
    {
        if ($excessAmount <= 0) {
            return [];
        }

        return [
            [
                'type' => 'prepayment',
                'description' => '转为预付款，用于未来账单',
                'amount' => $excessAmount,
            ],
            [
                'type' => 'refund',
                'description' => '退还给客户',
                'amount' => $excessAmount,
            ],
            [
                'type' => 'credit',
                'description' => '记为客户信用余额',
                'amount' => $excessAmount,
            ],
        ];
    }

    /**
     * 批量自动分配
     */
    public function batchAutoAllocate(
        array $paymentIds,
        PaymentAllocationStrategy $strategy = PaymentAllocationStrategy::OLDEST_FIRST
    ): array {
        $results = [];

        DB::transaction(function () use ($paymentIds, $strategy, &$results) {
            foreach ($paymentIds as $paymentId) {
                try {
                    $payment = Payment::findOrFail($paymentId);
                    $allocations = $this->autoAllocate($payment, $strategy);

                    $results[] = [
                        'payment_id' => $paymentId,
                        'success' => true,
                        'allocations_count' => count($allocations),
                        'allocated_amount' => array_sum(array_column($allocations, 'amount')),
                        'allocations' => $allocations,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'payment_id' => $paymentId,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return $results;
    }

    /**
     * 自动分配并处理优惠减免
     */
    public function autoAllocateWithDiscount(
        Payment $payment,
        PaymentAllocationStrategy $strategy = PaymentAllocationStrategy::OLDEST_FIRST,
        bool $allowDiscount = true,
        ?int $approvedBy = null
    ): array {
        // 使用 MoneyHelper 进行精确比较
        if (\App\Helpers\MoneyHelper::isZeroOrNegative($payment->unallocated_amount)) {
            return [
                'allocations' => [],
                'discounts' => [],
                'message' => '没有未分配金额',
            ];
        }

        // 获取客户的未付账单
        $unpaidInvoices = $this->getUnpaidInvoices($payment, $strategy);

        if ($unpaidInvoices->isEmpty()) {
            return [
                'allocations' => [],
                'discounts' => [],
                'message' => '没有找到未付账单',
            ];
        }

        // 初始化结果变量，避免未定义问题
        $result = [
            'allocations' => [],
            'discounts' => [],
            'message' => '自动分配完成',
        ];

        DB::transaction(function () use ($payment, $unpaidInvoices, $allowDiscount, $approvedBy, &$result) {
            // 先执行正常的分配
            $allocations = $this->performAllocation($payment, $unpaidInvoices);

            $discounts = [];

            // 如果允许优惠减免且还有未分配金额，检查是否可以进行优惠减免
            $freshPayment = $payment->fresh();
            if ($allowDiscount && \App\Helpers\MoneyHelper::isPositive($freshPayment->unallocated_amount)) {
                $discountService = new \App\Services\PaymentDiscountService;
                $gapInfo = $discountService->detectPaymentGap($freshPayment);

                if ($gapInfo['has_gap'] && $gapInfo['can_apply_discount']) {
                    // 自动创建优惠减免记录
                    $discountData = $this->generateDiscountData($unpaidInvoices, $gapInfo['gap_amount']);

                    if (! empty($discountData) && $approvedBy) {
                        try {
                            $discountResult = $discountService->processDiscountScenario(
                                $freshPayment,
                                $discountData,
                                $approvedBy
                            );
                            $discounts = $discountResult['discounts'] ?? [];
                        } catch (\Exception $e) {
                            // 如果优惠减免失败，记录错误但不影响正常分配
                            Log::warning('自动优惠减免失败: '.$e->getMessage());
                        }
                    }
                }
            }

            $result = [
                'allocations' => $allocations,
                'discounts' => $discounts,
                'message' => '自动分配完成'.(! empty($discounts) ? '，已处理优惠减免' : ''),
            ];
        });

        return $result;
    }

    /**
     * 生成优惠减免数据
     */
    private function generateDiscountData(Collection $invoices, float $totalDiscountAmount): array
    {
        $discountData = [];
        $remainingDiscount = $totalDiscountAmount;

        // 按账单的实际剩余金额分配优惠减免
        foreach ($invoices as $invoice) {
            // 使用 MoneyHelper 进行精确比较
            if (\App\Helpers\MoneyHelper::isZeroOrNegative($remainingDiscount)) {
                break;
            }

            $actualRemaining = $invoice->actual_remaining_amount;
            if (\App\Helpers\MoneyHelper::isPositive($actualRemaining)) {
                $discountAmount = \App\Helpers\MoneyHelper::toFloat(
                    \App\Helpers\MoneyHelper::min($remainingDiscount, $actualRemaining)
                );

                if (\App\Helpers\MoneyHelper::isPositive($discountAmount)) {
                    $discountData[] = [
                        'invoice_id' => $invoice->id,
                        'amount' => $discountAmount,
                        'type' => $this->suggestDiscountType($discountAmount),
                        'reason' => '自动优惠减免',
                    ];

                    $remainingDiscount = \App\Helpers\MoneyHelper::toFloat(
                        \App\Helpers\MoneyHelper::subtract($remainingDiscount, $discountAmount)
                    );
                }
            }
        }

        return $discountData;
    }

    /**
     * 建议折扣类型
     */
    private function suggestDiscountType(float $amount): string
    {
        if ($amount <= 10) {
            return \App\Models\PaymentDiscount::TYPE_DISCOUNT;
        } elseif ($amount <= 100) {
            return \App\Models\PaymentDiscount::TYPE_PROMOTION;
        } else {
            return \App\Models\PaymentDiscount::TYPE_WRITE_OFF;
        }
    }

    /**
     * 获取包含优惠减免的分配建议
     */
    public function getAllocationSuggestionWithDiscount(
        Payment $payment,
        PaymentAllocationStrategy $strategy = PaymentAllocationStrategy::OLDEST_FIRST,
        bool $includeDiscount = true
    ): array {
        $basicSuggestion = $this->getAllocationSuggestion($payment, $strategy);

        if (! $includeDiscount || $basicSuggestion['can_fully_allocate']) {
            return $basicSuggestion;
        }

        // 如果不能完全分配，计算优惠减免建议
        $discountService = new \App\Services\PaymentDiscountService;
        $gapInfo = $discountService->detectPaymentGap($payment);

        if ($gapInfo['has_gap'] && $gapInfo['can_apply_discount']) {
            $unpaidInvoices = $this->getUnpaidInvoices($payment, $strategy);
            $discountSuggestions = $this->generateDiscountData($unpaidInvoices, $gapInfo['gap_amount']);

            $basicSuggestion['discount_suggestions'] = $discountSuggestions;
            $basicSuggestion['gap_info'] = $gapInfo;
            $basicSuggestion['can_apply_discount'] = true;
        } else {
            $basicSuggestion['can_apply_discount'] = false;
        }

        return $basicSuggestion;
    }
}
