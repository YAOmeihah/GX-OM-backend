<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 仪表盘
 *
 * 仪表盘数据统计和概览信息
 */
class DashboardController extends ApiController
{
    /**
     * 获取仪表盘概览
     *
     * 获取系统概览数据，包括今日、昨日和总体统计。
     * 非管理员用户只能看到自己所属门店的数据统计。
     * 支持通过 store_id 参数筛选特定门店的数据。
     *
     * @queryParam store_id integer 可选，按门店ID筛选统计数据（用户需有该门店权限） Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "summary": { "total_customers": 100, "total_invoices": 500, "total_payments": 300, "total_stores": 5 },
     *     "today": { "invoice_amount": "10000.00", "invoice_count": 5, "payment_amount": "8000.00", "payment_count": 3, "discount_amount": "500.00", "new_customers": 2 },
     *     "yesterday": { "invoice_amount": "9000.00", "invoice_count": 4, "payment_amount": "6000.00", "payment_count": 2, "discount_amount": "300.00", "new_customers": 1 },
     *     "overall": { "invoice_amount": "500000.00", "paid_amount": "350000.00", "outstanding_amount": "145000.00", "discount_amount": "5000.00", "collection_rate": 70.0 },
     *     "invoice_status_distribution": { "unpaid": 50, "partially_paid": 100, "paid": 300, "overdue": 50 }
     *   }
     * }
     */
    public function overview(Request $request)
    {
        $user = Auth::user();

        // 支持门店筛选
        $filterStoreId = $request->input('store_id') ? (int) $request->input('store_id') : null;

        // 根据用户权限确定可访问的门店
        $storeIds = $this->getAccessibleStoreIds($filterStoreId);

        // 判断是否需要应用门店过滤
        $shouldFilterByStore = ($filterStoreId !== null) || ! $this->isAdmin();

        // 日期定义
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        // ========== 基础统计 ==========
        $totalCustomers = Customer::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->count();

        $totalInvoices = Invoice::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->count();

        $totalPayments = Payment::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->count();

        // 用户可访问的门店数
        $userAccessibleStoreCount = $this->isAdmin()
            ? Store::count()
            : Auth::user()->stores->count();

        // ========== 今日统计 ==========
        $todayInvoiceStats = Invoice::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $today)
            ->selectRaw('SUM(amount) as total_amount, COUNT(*) as count')
            ->first();

        $todayPaymentStats = Payment::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $today)
            ->selectRaw('SUM(amount) as total_amount, COUNT(*) as count')
            ->first();

        $todayDiscountAmount = \App\Models\PaymentDiscount::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereHas('invoice', function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            });
        })->whereDate('created_at', $today)->sum('discount_amount');

        $todayNewCustomers = Customer::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $today)->count();

        // ========== 昨日统计 ==========
        $yesterdayInvoiceStats = Invoice::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $yesterday)
            ->selectRaw('SUM(amount) as total_amount, COUNT(*) as count')
            ->first();

        $yesterdayPaymentStats = Payment::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $yesterday)
            ->selectRaw('SUM(amount) as total_amount, COUNT(*) as count')
            ->first();

        $yesterdayDiscountAmount = \App\Models\PaymentDiscount::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereHas('invoice', function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            });
        })->whereDate('created_at', $yesterday)->sum('discount_amount');

        $yesterdayNewCustomers = Customer::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->whereDate('created_at', $yesterday)->count();

        // ========== 总体统计 ==========
        $overallInvoiceStats = Invoice::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->selectRaw('SUM(amount) as total_amount, SUM(paid_amount) as total_paid')->first();

        $overallDiscountAmount = \App\Models\PaymentDiscount::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereHas('invoice', function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            });
        })->sum('discount_amount');

        // 实际待收 = 账单总额 - 已付 - 减免
        $totalInvoiceAmount = $overallInvoiceStats->total_amount ?? 0;
        $totalPaidAmount = $overallInvoiceStats->total_paid ?? 0;
        $actualOutstanding = $totalInvoiceAmount - $totalPaidAmount - $overallDiscountAmount;
        if ($actualOutstanding < 0) {
            $actualOutstanding = 0;
        }

        // 收款率
        $collectionRate = $totalInvoiceAmount > 0
            ? round(($totalPaidAmount / $totalInvoiceAmount) * 100, 1)
            : 0;

        // ========== 账单状态分布 ==========
        $invoiceStatusStats = Invoice::when($shouldFilterByStore, function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        })->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return $this->successResponse([
            'summary' => [
                'total_customers' => $totalCustomers,
                'total_invoices' => $totalInvoices,
                'total_payments' => $totalPayments,
                'total_stores' => $userAccessibleStoreCount,
            ],
            'today' => [
                'invoice_amount' => number_format($todayInvoiceStats->total_amount ?? 0, 2, '.', ''),
                'invoice_count' => $todayInvoiceStats->count ?? 0,
                'payment_amount' => number_format($todayPaymentStats->total_amount ?? 0, 2, '.', ''),
                'payment_count' => $todayPaymentStats->count ?? 0,
                'discount_amount' => number_format($todayDiscountAmount, 2, '.', ''),
                'new_customers' => $todayNewCustomers,
            ],
            'yesterday' => [
                'invoice_amount' => number_format($yesterdayInvoiceStats->total_amount ?? 0, 2, '.', ''),
                'invoice_count' => $yesterdayInvoiceStats->count ?? 0,
                'payment_amount' => number_format($yesterdayPaymentStats->total_amount ?? 0, 2, '.', ''),
                'payment_count' => $yesterdayPaymentStats->count ?? 0,
                'discount_amount' => number_format($yesterdayDiscountAmount, 2, '.', ''),
                'new_customers' => $yesterdayNewCustomers,
            ],
            'overall' => [
                'invoice_amount' => number_format($totalInvoiceAmount, 2, '.', ''),
                'paid_amount' => number_format($totalPaidAmount, 2, '.', ''),
                'outstanding_amount' => number_format($actualOutstanding, 2, '.', ''),
                'discount_amount' => number_format($overallDiscountAmount, 2, '.', ''),
                'collection_rate' => $collectionRate,
            ],
            'invoice_status_distribution' => [
                'unpaid' => $invoiceStatusStats['unpaid'] ?? 0,
                'partially_paid' => $invoiceStatusStats['partially_paid'] ?? 0,
                'paid' => $invoiceStatusStats['paid'] ?? 0,
                'overdue' => $invoiceStatusStats['overdue'] ?? 0,
            ],
        ]);
    }

    /**
     * 获取详细统计数据
     *
     * 获取更详细的统计数据，支持按时间范围筛选。
     * 管理员可以看到各门店的分别统计。
     *
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "period": {
     *       "start_date": "2024-01-01",
     *       "end_date": "2024-12-31"
     *     },
     *     "customers": {
     *       "total_customers": 100,
     *       "customers_with_debt": 30
     *     },
     *     "invoices": {
     *       "count": 500,
     *       "total_amount": "500000.00",
     *       "paid_amount": "350000.00",
     *       "average_amount": "1000.00"
     *     },
     *     "payments": {
     *       "count": 300,
     *       "total_amount": "350000.00",
     *       "average_amount": "1166.67"
     *     },
     *     "stores": [
     *       {
     *         "store_id": 1,
     *         "store_name": "总店",
     *         "invoice_count": 200,
     *         "total_amount": 200000,
     *         "paid_amount": 150000
     *       },
     *       {
     *         "store_id": 2,
     *         "store_name": "分店A",
     *         "invoice_count": 150,
     *         "total_amount": 150000,
     *         "paid_amount": 100000
     *       }
     *     ]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function statistics(Request $request)
    {
        $user = Auth::user();
        $storeIds = $this->getAccessibleStoreIds();

        // 支持时间范围筛选
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // 客户统计
        $customerStats = [
            'total_customers' => Customer::when(! $this->isAdmin(), function ($query) use ($storeIds) {
                return $query->whereIn('store_id', $storeIds);
            })->count(),
            'customers_with_debt' => Customer::whereHas('unpaidInvoices')->when(! $this->isAdmin(), function ($query) use ($storeIds) {
                return $query->whereIn('store_id', $storeIds);
            })->count(),
        ];

        // 账单统计（按时间筛选）
        $invoiceQuery = Invoice::when(! $this->isAdmin(), function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        });

        if ($startDate) {
            $invoiceQuery->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $invoiceQuery->where('created_at', '<=', $endDate);
        }

        $invoiceStats = $invoiceQuery->selectRaw('
            COUNT(*) as total_count,
            SUM(amount) as total_amount,
            SUM(paid_amount) as total_paid,
            AVG(amount) as avg_amount
        ')->first();

        // 还款统计（按时间筛选）
        $paymentQuery = Payment::when(! $this->isAdmin(), function ($query) use ($storeIds) {
            return $query->whereIn('store_id', $storeIds);
        });

        if ($startDate) {
            $paymentQuery->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $paymentQuery->where('created_at', '<=', $endDate);
        }

        $paymentStats = $paymentQuery->selectRaw('
            COUNT(*) as total_count,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount
        ')->first();

        // 门店统计（仅管理员可见）
        $storeStats = [];
        if ($this->isAdmin()) {
            $storeStats = Store::with([
                'invoices' => function ($query) use ($startDate, $endDate) {
                    if ($startDate) {
                        $query->where('created_at', '>=', $startDate);
                    }
                    if ($endDate) {
                        $query->where('created_at', '<=', $endDate);
                    }
                },
            ])->get()->map(function ($store) {
                return [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'invoice_count' => $store->invoices->count(),
                    'total_amount' => $store->invoices->sum('amount'),
                    'paid_amount' => $store->invoices->sum('paid_amount'),
                ];
            });
        }

        return $this->successResponse([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'customers' => $customerStats,
            'invoices' => [
                'count' => $invoiceStats->total_count ?? 0,
                'total_amount' => number_format($invoiceStats->total_amount ?? 0, 2, '.', ''),
                'paid_amount' => number_format($invoiceStats->total_paid ?? 0, 2, '.', ''),
                'average_amount' => number_format($invoiceStats->avg_amount ?? 0, 2, '.', ''),
            ],
            'payments' => [
                'count' => $paymentStats->total_count ?? 0,
                'total_amount' => number_format($paymentStats->total_amount ?? 0, 2, '.', ''),
                'average_amount' => number_format($paymentStats->avg_amount ?? 0, 2, '.', ''),
            ],
            'stores' => $storeStats,
        ]);
    }

    /**
     * 获取用户可访问的门店ID列表
     *
     * @param  int|null  $filterStoreId  可选，指定筛选的门店ID
     * @return array 门店ID列表
     */
    private function getAccessibleStoreIds(?int $filterStoreId = null): array
    {
        // 如果指定了门店ID，先验证用户权限
        if ($filterStoreId !== null) {
            // 管理员可以访问任意门店
            if ($this->isAdmin()) {
                return [$filterStoreId];
            }
            // 非管理员需验证是否属于该门店
            if ($this->belongsToStore($filterStoreId)) {
                return [$filterStoreId];
            }

            // 无权访问该门店，返回空数组
            return [];
        }

        // 未指定门店ID时，返回用户可访问的所有门店
        if ($this->isAdmin()) {
            return Store::pluck('id')->toArray();
        }

        return Auth::user()->stores->pluck('id')->toArray();
    }
}
