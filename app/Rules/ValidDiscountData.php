<?php

namespace App\Rules;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDiscountData implements ValidationRule
{
    protected Payment $payment;
    protected float $maxDiscountAmount;

    public function __construct(Payment $payment, float $maxDiscountAmount = null)
    {
        $this->payment = $payment;
        $this->maxDiscountAmount = $maxDiscountAmount ?? config('payment.max_discount_amount', 1000);
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('优惠减免数据必须是数组格式');
            return;
        }

        $totalDiscountAmount = 0;
        $processedInvoices = [];

        foreach ($value as $index => $discountItem) {
            $itemAttribute = "{$attribute}.{$index}";
            
            // 验证必需字段
            if (!isset($discountItem['invoice_id']) || !isset($discountItem['amount'])) {
                $fail("{$itemAttribute}: 缺少必需的字段 invoice_id 或 amount");
                continue;
            }

            $invoiceId = $discountItem['invoice_id'];
            $amount = $discountItem['amount'];
            $type = $discountItem['type'] ?? PaymentDiscount::TYPE_DISCOUNT;

            // 验证金额
            if (!is_numeric($amount) || $amount <= 0) {
                $fail("{$itemAttribute}.amount: 优惠减免金额必须是大于0的数字");
                continue;
            }

            // 验证折扣类型
            $validTypes = [
                PaymentDiscount::TYPE_WRITE_OFF,
                PaymentDiscount::TYPE_DISCOUNT,
                PaymentDiscount::TYPE_PROMOTION
            ];
            if (!in_array($type, $validTypes)) {
                $fail("{$itemAttribute}.type: 无效的折扣类型");
                continue;
            }

            // 验证账单是否存在
            $invoice = Invoice::find($invoiceId);
            if (!$invoice) {
                $fail("{$itemAttribute}.invoice_id: 账单不存在");
                continue;
            }

            // 验证账单是否属于同一客户和门店
            if ($invoice->customer_id !== $this->payment->customer_id) {
                $fail("{$itemAttribute}.invoice_id: 账单不属于该客户");
                continue;
            }

            if ($invoice->store_id !== $this->payment->store_id) {
                $fail("{$itemAttribute}.invoice_id: 账单不属于该门店");
                continue;
            }

            // 验证账单状态
            if (!in_array($invoice->status, ['unpaid', 'partially_paid', 'overdue'])) {
                $fail("{$itemAttribute}.invoice_id: 账单状态不允许进行优惠减免");
                continue;
            }

            // 验证是否重复处理同一账单
            if (in_array($invoiceId, $processedInvoices)) {
                $fail("{$itemAttribute}.invoice_id: 不能对同一账单重复进行优惠减免");
                continue;
            }
            $processedInvoices[] = $invoiceId;

            // 验证优惠减免金额不超过账单实际剩余金额
            $actualRemaining = $invoice->actual_remaining_amount;
            if ($amount > $actualRemaining) {
                $fail("{$itemAttribute}.amount: 优惠减免金额({$amount})超过了账单实际剩余金额({$actualRemaining})");
                continue;
            }

            // 验证单笔优惠减免金额限制
            if ($amount > $this->maxDiscountAmount) {
                $fail("{$itemAttribute}.amount: 单笔优惠减免金额不能超过{$this->maxDiscountAmount}元");
                continue;
            }

            $totalDiscountAmount += $amount;
        }

        // 验证总优惠减免金额
        if ($totalDiscountAmount > $this->maxDiscountAmount * 2) {
            $fail("总优惠减免金额不能超过" . ($this->maxDiscountAmount * 2) . "元");
        }

        // 验证优惠减免金额不超过还款未分配金额
        $unallocatedAmount = $this->payment->unallocated_amount;
        if ($totalDiscountAmount > $unallocatedAmount) {
            $fail("优惠减免总金额({$totalDiscountAmount})不能超过还款未分配金额({$unallocatedAmount})");
        }
    }
}
