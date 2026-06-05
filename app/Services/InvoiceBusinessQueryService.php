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

    public function summary(User $user, int $storeId): array
    {
        return $this->summaryForStores($user, [$storeId]);
    }

    public function summaryForStores(User $user, array $storeIds): array
    {
        $storeIds = $this->normalizeStoreIds($storeIds);
        $this->ensureStoresVisible($user, $storeIds);

        $baseQuery = Invoice::query()->whereIn('store_id', $storeIds);
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        return [
            'total' => [
                'count' => (clone $baseQuery)->count(),
            ],
            'today' => $this->todaySummaryCount($baseQuery, $today, $yesterday),
            'unpaid' => $this->summaryCount($baseQuery, $today, $yesterday, ['unpaid']),
            'outstanding' => $this->summaryCount($baseQuery, $today, $yesterday, self::OUTSTANDING_STATUSES),
            'overdue' => $this->summaryCount($baseQuery, $today, $yesterday, ['overdue']),
        ];
    }

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

    private function todaySummaryCount(Builder $baseQuery, string $today, string $yesterday): array
    {
        $todayCount = $this->countInvoices($baseQuery, null, $today);
        $yesterdayCount = $this->countInvoices($baseQuery, null, $yesterday);

        return [
            'count' => $todayCount,
            'yesterday_count' => $yesterdayCount,
            'delta' => $todayCount - $yesterdayCount,
        ];
    }

    private function summaryCount(Builder $baseQuery, string $today, string $yesterday, ?array $statuses = null): array
    {
        $count = $statuses === null
            ? $this->countInvoices($baseQuery)
            : $this->countInvoices($baseQuery, $statuses);
        $todayCount = $this->countInvoices($baseQuery, $statuses, $today);
        $yesterdayCount = $this->countInvoices($baseQuery, $statuses, $yesterday);

        return [
            'count' => $count,
            'today_count' => $todayCount,
            'yesterday_count' => $yesterdayCount,
            'delta' => $todayCount - $yesterdayCount,
        ];
    }

    private function countInvoices(Builder $baseQuery, ?array $statuses = null, ?string $date = null): int
    {
        $query = clone $baseQuery;

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if ($date !== null) {
            $query->whereDate('created_at', $date);
        }

        return $query->count();
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
        $this->ensureStoresVisible($user, [$storeId]);
    }

    private function ensureStoresVisible(User $user, array $storeIds): void
    {
        if ($user->isAdmin() || empty($storeIds)) {
            return;
        }

        $visibleStoreIds = $user->stores()
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! empty(array_diff($storeIds, $visibleStoreIds))) {
            throw new HttpException(403, '权限不足');
        }
    }

    private function normalizeStoreIds(array $storeIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($storeId) => (int) $storeId, $storeIds),
            fn (int $storeId) => $storeId > 0,
        )));
    }

    private function actualRemainingExpression(): string
    {
        return '(invoices.amount - invoices.paid_amount - COALESCE(discount_sums.discount_total, 0))';
    }
}
