<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerWorkbenchService
{
    public function appendListFlags(LengthAwarePaginator $customers, array $storeIds, string $today): LengthAwarePaginator
    {
        $customerIds = $customers->getCollection()->pluck('id')->all();
        $overdueCustomerIds = [];

        if ($customerIds !== []) {
            $overdueCustomerIds = DB::table('invoices')
                ->whereIn('customer_id', $customerIds)
                ->whereIn('store_id', $storeIds)
                ->where('status', 'overdue')
                ->distinct()
                ->pluck('customer_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $overdueCustomerIds = array_flip($overdueCustomerIds);
        }

        $customers->getCollection()->transform(function ($customer) use ($overdueCustomerIds, $today) {
            $totalDebt = (float) ($customer->total_debt ?? 0);
            $lastTransactionDate = $customer->last_transaction_at
                ? Carbon::parse($customer->last_transaction_at)->toDateString()
                : null;

            $customer->setAttribute('is_debt_customer', $totalDebt > 0);
            $customer->setAttribute('has_today_transaction', $lastTransactionDate === $today);
            $customer->setAttribute('is_overdue', isset($overdueCustomerIds[(int) $customer->id]));

            return $customer;
        });

        return $customers;
    }

    public function summary(array $storeIds, string $date, int $trendDays): array
    {
        $yesterday = Carbon::parse($date)->subDay()->toDateString();

        $debtSummary = $this->debtSummaryAsOf($storeIds, $date);
        $yesterdayDebtSummary = $this->debtSummaryAsOf($storeIds, $yesterday);
        $todayPaymentAmount = $this->paymentAmountOnDate($storeIds, $date);
        $yesterdayPaymentAmount = $this->paymentAmountOnDate($storeIds, $yesterday);

        return [
            'debt' => [
                'total_amount' => $this->formatMoney($debtSummary['amount']),
                'yesterday_total_amount' => $this->formatMoney($yesterdayDebtSummary['amount']),
                'delta_amount' => $this->formatMoney($debtSummary['amount'] - $yesterdayDebtSummary['amount']),
                'trend' => $this->debtTrend($storeIds, $date, $trendDays),
            ],
            'debt_customers' => [
                'count' => $debtSummary['customer_count'],
                'yesterday_count' => $yesterdayDebtSummary['customer_count'],
                'delta_count' => $debtSummary['customer_count'] - $yesterdayDebtSummary['customer_count'],
            ],
            'today_payments' => [
                'amount' => $this->formatMoney($todayPaymentAmount),
                'yesterday_amount' => $this->formatMoney($yesterdayPaymentAmount),
                'delta_amount' => $this->formatMoney($todayPaymentAmount - $yesterdayPaymentAmount),
                'customer_count' => $this->paymentCustomerCountOnDate($storeIds, $date),
            ],
            'tabs' => [
                'all' => Customer::whereIn('store_id', $storeIds)->count(),
                'debt' => $debtSummary['customer_count'],
                'today_transaction' => $this->todayTransactionCustomerCount($storeIds, $date),
                'overdue' => $this->overdueCustomerCount($storeIds),
                'abnormal' => 0,
            ],
        ];
    }

    private function debtSummaryAsOf(array $storeIds, string $date): array
    {
        $summary = DB::query()
            ->fromSub($this->debtSnapshotQuery($storeIds, $date), 'debt_snapshot')
            ->selectRaw('COALESCE(SUM(debt_amount), 0) as total_debt')
            ->selectRaw('SUM(CASE WHEN debt_amount > 0 THEN 1 ELSE 0 END) as debt_customer_count')
            ->first();

        return [
            'amount' => (float) ($summary->total_debt ?? 0),
            'customer_count' => (int) ($summary->debt_customer_count ?? 0),
        ];
    }

    private function debtTrend(array $storeIds, string $date, int $days): array
    {
        $end = Carbon::parse($date);
        $start = $end->copy()->subDays($days - 1);
        $endOfDay = $end->copy()->endOfDay();
        $points = [];

        $invoices = DB::table('invoices')
            ->whereIn('store_id', $storeIds)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '<=', $endOfDay)
            ->get(['id', 'amount', 'created_at']);

        $invoiceIds = $invoices->pluck('id')->all();
        $allocationsByInvoice = [];
        $discountsByInvoice = [];

        if ($invoiceIds !== []) {
            DB::table('payment_allocations')
                ->whereIn('invoice_id', $invoiceIds)
                ->where('created_at', '<=', $endOfDay)
                ->get(['invoice_id', 'amount', 'created_at'])
                ->each(function ($allocation) use (&$allocationsByInvoice) {
                    $invoiceId = (int) $allocation->invoice_id;
                    $allocationsByInvoice[$invoiceId][] = [
                        'amount' => (float) $allocation->amount,
                        'created_at' => Carbon::parse($allocation->created_at)->getTimestamp(),
                    ];
                });

            DB::table('payment_discounts')
                ->whereIn('invoice_id', $invoiceIds)
                ->where('created_at', '<=', $endOfDay)
                ->get(['invoice_id', 'discount_amount', 'created_at'])
                ->each(function ($discount) use (&$discountsByInvoice) {
                    $invoiceId = (int) $discount->invoice_id;
                    $discountsByInvoice[$invoiceId][] = [
                        'amount' => (float) $discount->discount_amount,
                        'created_at' => Carbon::parse($discount->created_at)->getTimestamp(),
                    ];
                });
        }

        $invoiceSnapshots = $invoices->map(fn ($invoice) => [
            'id' => (int) $invoice->id,
            'amount' => (float) $invoice->amount,
            'created_at' => Carbon::parse($invoice->created_at)->getTimestamp(),
        ]);

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $pointEnd = $cursor->copy()->endOfDay()->getTimestamp();
            $amount = 0.0;

            foreach ($invoiceSnapshots as $invoice) {
                if ($invoice['created_at'] > $pointEnd) {
                    continue;
                }

                $paidAmount = $this->sumEventsAsOf($allocationsByInvoice[$invoice['id']] ?? [], $pointEnd);
                $discountAmount = $this->sumEventsAsOf($discountsByInvoice[$invoice['id']] ?? [], $pointEnd);
                $amount += max($invoice['amount'] - $paidAmount - $discountAmount, 0);
            }

            $points[] = [
                'date' => $cursor->toDateString(),
                'amount' => $this->formatMoney($amount),
            ];
        }

        return $points;
    }

    private function sumEventsAsOf(array $events, int $timestamp): float
    {
        $amount = 0.0;

        foreach ($events as $event) {
            if ($event['created_at'] <= $timestamp) {
                $amount += $event['amount'];
            }
        }

        return $amount;
    }

    private function debtSnapshotQuery(array $storeIds, string $date)
    {
        $endOfDay = Carbon::parse($date)->endOfDay();
        $allocationTotals = DB::table('payment_allocations')
            ->where('created_at', '<=', $endOfDay)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as allocated_sum');
        $discountTotals = DB::table('payment_discounts')
            ->where('created_at', '<=', $endOfDay)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(discount_amount) as discount_sum');

        return DB::table('invoices')
            ->leftJoinSub(
                $allocationTotals,
                'payment_allocation_totals',
                'invoices.id',
                '=',
                'payment_allocation_totals.invoice_id'
            )
            ->leftJoinSub(
                $discountTotals,
                'payment_discount_totals',
                'invoices.id',
                '=',
                'payment_discount_totals.invoice_id'
            )
            ->whereIn('invoices.store_id', $storeIds)
            ->where('invoices.status', '!=', 'cancelled')
            ->where('invoices.created_at', '<=', $endOfDay)
            ->groupBy('invoices.customer_id')
            ->selectRaw('invoices.customer_id')
            ->selectRaw('SUM(CASE WHEN invoices.amount - COALESCE(payment_allocation_totals.allocated_sum, 0) - COALESCE(payment_discount_totals.discount_sum, 0) > 0 THEN invoices.amount - COALESCE(payment_allocation_totals.allocated_sum, 0) - COALESCE(payment_discount_totals.discount_sum, 0) ELSE 0 END) as debt_amount');
    }

    private function paymentAmountOnDate(array $storeIds, string $date): float
    {
        return (float) Payment::whereIn('store_id', $storeIds)
            ->whereDate('created_at', $date)
            ->sum('amount');
    }

    private function paymentCustomerCountOnDate(array $storeIds, string $date): int
    {
        return Payment::whereIn('store_id', $storeIds)
            ->whereDate('created_at', $date)
            ->distinct('customer_id')
            ->count('customer_id');
    }

    private function todayTransactionCustomerCount(array $storeIds, string $date): int
    {
        return DB::table('customer_store_stats')
            ->whereIn('store_id', $storeIds)
            ->whereDate('last_transaction_at', $date)
            ->distinct('customer_id')
            ->count('customer_id');
    }

    private function overdueCustomerCount(array $storeIds): int
    {
        return DB::table('invoices')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'overdue')
            ->distinct('customer_id')
            ->count('customer_id');
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
