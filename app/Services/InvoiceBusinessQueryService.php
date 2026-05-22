<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentDiscount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceBusinessQueryService
{
    private const OUTSTANDING_STATUSES = ['unpaid', 'partially_paid', 'overdue'];

    public function allocatable(User $user, int $storeId, int $customerId, int $perPage): array
    {
        $this->ensureStoreVisible($user, $storeId);

        $query = $this->outstandingInvoiceQuery()
            ->where('invoices.store_id', $storeId)
            ->where('invoices.customer_id', $customerId)
            ->with(['store:id,name', 'customer:id,name,phone', 'createdBy:id,name', 'discounts']);

        return $this->paginateWithSummary($query, $perPage, [
            'count_key' => 'outstanding_count',
            'total_key' => 'actual_remaining_total',
        ]);
    }

    public function todayUnpaidPrintTasks(User $user, int $storeId, string $date, int $perPage): array
    {
        $this->ensureStoreVisible($user, $storeId);

        $query = $this->outstandingInvoiceQuery()
            ->where('invoices.store_id', $storeId)
            ->whereDate('invoices.created_at', $date)
            ->with(['store:id,name', 'customer:id,name,phone', 'createdBy:id,name', 'discounts']);

        return $this->paginateWithSummary($query, $perPage, [
            'count_key' => 'task_count',
            'total_key' => 'actual_remaining_total',
        ]);
    }

    public function printDetails(User $user, array $invoiceIds): Collection
    {
        if (count($invoiceIds) !== count(array_unique($invoiceIds))) {
            throw ValidationException::withMessages([
                'invoice_ids' => ['账单ID不能重复'],
            ]);
        }

        $query = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with([
                'store',
                'customer',
                'createdBy',
                'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'discounts',
                'paymentAllocations',
            ]);

        if (! $user->isAdmin()) {
            $query->whereIn('store_id', $user->stores()->pluck('stores.id'));
        }

        $invoices = $query->get()->keyBy('id');

        if ($invoices->count() !== count($invoiceIds)) {
            throw ValidationException::withMessages([
                'invoice_ids' => ['部分账单不存在或无权访问'],
            ]);
        }

        return collect($invoiceIds)
            ->map(fn (int $invoiceId) => $this->invoicePayload($invoices->get($invoiceId)))
            ->values();
    }

    private function outstandingInvoiceQuery(): Builder
    {
        $discountSubquery = PaymentDiscount::query()
            ->select('invoice_id', DB::raw('COALESCE(SUM(discount_amount), 0) as discount_total'))
            ->groupBy('invoice_id');

        return Invoice::query()
            ->leftJoinSub($discountSubquery, 'discount_sums', function ($join) {
                $join->on('discount_sums.invoice_id', '=', 'invoices.id');
            })
            ->select('invoices.*')
            ->selectRaw($this->actualRemainingExpression().' as actual_remaining_amount')
            ->whereIn('invoices.status', self::OUTSTANDING_STATUSES)
            ->whereRaw($this->actualRemainingExpression().' > 0')
            ->orderBy('invoices.created_at')
            ->orderBy('invoices.id');
    }

    private function paginateWithSummary(Builder $query, int $perPage, array $summaryKeys): array
    {
        $count = (clone $query)->toBase()->getCountForPagination();
        $total = (float) (clone $query)
            ->reorder()
            ->toBase()
            ->sum(DB::raw($this->actualRemainingExpression()));

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (Invoice $invoice) => $this->invoicePayload($invoice));

        $data = $paginator->toArray();
        $data['summary'] = [
            $summaryKeys['count_key'] => $count,
            $summaryKeys['total_key'] => $this->formatMoney($total),
        ];

        return $data;
    }

    private function invoicePayload(Invoice $invoice): array
    {
        $data = $invoice->toArray();
        $data['total_discount_amount'] = $this->formatMoney($invoice->total_discount_amount);
        $data['actual_remaining_amount'] = $this->formatMoney($invoice->actual_remaining_amount);

        return $data;
    }

    private function formatMoney(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function ensureStoreVisible(User $user, int $storeId): void
    {
        if (! $user->isAdmin() && ! $user->belongsToStore($storeId)) {
            throw new HttpException(403, '权限不足');
        }
    }

    private function actualRemainingExpression(): string
    {
        return '(invoices.amount - invoices.paid_amount - COALESCE(discount_sums.discount_total, 0))';
    }
}
