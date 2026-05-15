<?php

namespace App\Services;

use App\Helpers\MoneyHelper;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentCreationService
{
    public function __construct(
        private readonly PaymentDiscountService $discounts,
    ) {}

    /**
     * @throws DiscountValidationException
     */
    public function create(array $validated, User $user): Payment
    {
        $customer = Customer::findOrFail($validated['customer_id']);
        if ($customer->unpaidInvoices()->count() === 0) {
            throw new DiscountValidationException('该客户没有未付清的账单', 422);
        }

        $store = Store::findOrFail($validated['store_id']);
        $paymentNumber = 'PAY-'.$store->code.'-'.date('Ymd').'-'.Str::random(5);

        $payment = DB::transaction(function () use ($validated, $user, $customer, $paymentNumber) {
            $payment = Payment::create([
                'payment_number' => $paymentNumber,
                'store_id' => $validated['store_id'],
                'customer_id' => $validated['customer_id'],
                'received_by' => $user->id,
                'amount' => $validated['amount'],
                'allocated_amount' => 0,
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            if (! empty($validated['apply_discount']) && ! empty($validated['discount_data'])) {
                $this->createPaymentWithDiscounts($payment, $validated, $user, $customer);

                return $payment;
            }

            $this->createPaymentAllocations($payment, $validated, $user);

            return $payment;
        });

        $relations = ['allocations.invoice', 'customer', 'store', 'receivedBy:id,name'];
        if (! empty($validated['apply_discount']) && ! empty($validated['discount_data'])) {
            $relations[] = 'discounts.invoice';
        }

        $payment->load($relations);

        if ($payment->relationLoaded('receivedBy')) {
            $payment->setAttribute('received_by', $payment->receivedBy);
        }

        return $payment;
    }

    /**
     * @throws DiscountValidationException
     */
    private function createPaymentWithDiscounts(Payment $payment, array $validated, User $user, Customer $customer): void
    {
        $totalAllocated = collect($validated['allocations'] ?? [])->sum('amount');
        $totalDiscount = collect($validated['discount_data'])->sum('amount');
        $totalDebt = $customer->unpaidInvoices()->with('discounts')->get()->sum('actual_remaining_amount');
        $intendedGap = MoneyHelper::subtract($totalDebt, $validated['amount']);

        if (MoneyHelper::isGreaterThan(
            MoneyHelper::add($totalAllocated, $totalDiscount),
            MoneyHelper::add($validated['amount'], max(0, $intendedGap))
        )) {
            throw new \Exception('分配金额与优惠减免总额超过了还款金额和差额');
        }

        $this->discounts->validateDiscountRequest(
            $payment,
            $validated['discount_data'],
            $user->id,
            'create_payment',
            $validated['allocations'] ?? []
        );

        $this->createPaymentAllocations($payment, $validated, $user, false);

        foreach ($validated['discount_data'] as $discountItem) {
            $invoice = Invoice::findOrFail($discountItem['invoice_id']);

            if ($invoice->customer_id != $validated['customer_id'] || $invoice->store_id != $validated['store_id']) {
                throw new \Exception('账单与还款的客户或门店不匹配');
            }

            $payment->createDiscount(
                $invoice,
                $discountItem['amount'],
                $discountItem['type'] ?? 'write_off',
                $discountItem['reason'] ?? '优惠抹零',
                $user->id
            );

            $invoice->refresh();
            $invoice->updateStatus();
        }
    }

    private function createPaymentAllocations(
        Payment $payment,
        array $validated,
        User $user,
        bool $validateTotalAllocated = true
    ): void {
        $totalAllocated = 0;

        if (! empty($validated['allocations'])) {
            foreach ($validated['allocations'] as $allocationData) {
                $invoice = Invoice::findOrFail($allocationData['invoice_id']);

                if ($invoice->customer_id != $validated['customer_id'] || $invoice->store_id != $validated['store_id']) {
                    throw new \Exception('账单与还款的客户或门店不匹配');
                }

                $invoice->loadMissing('discounts');
                $remainingAmount = $invoice->actual_remaining_amount;
                if ($allocationData['amount'] > $remainingAmount) {
                    throw new \Exception("账单 {$invoice->invoice_number} 的分配金额超过了剩余未付金额");
                }

                $payment->allocateToInvoice($invoice, $allocationData['amount'], $user->id);
                $totalAllocated += $allocationData['amount'];
            }
        }

        if ($validateTotalAllocated && $totalAllocated > $validated['amount']) {
            throw new \Exception('分配总金额超过了还款金额');
        }
    }
}
