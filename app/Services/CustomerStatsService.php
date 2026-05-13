<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerStoreStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerStatsService
{
    /**
     * 同步计算并更新指定客户在特定门店下的统计信息（总欠款、最后交易时间）
     *
     * @param int $customerId 客户 ID
     * @param int $storeId 门店 ID
     * @return CustomerStoreStat|null
     */
    public function syncCustomerStoreStats(int $customerId, int $storeId)
    {
        try {
            $customer = Customer::find($customerId);
            if (!$customer) {
                return null;
            }

            // 使用原生 DB 聚合查询替代 Eloquent 数据集合加载
            // 左连接 payment_discounts 到 invoices 分组，再一并计算
            // O(1) 的常数级性能
            $stats = DB::table('invoices AS i')
                ->leftJoin(DB::raw('(SELECT invoice_id, SUM(discount_amount) as disc_sum FROM payment_discounts GROUP BY invoice_id) AS pd'), 'i.id', '=', 'pd.invoice_id')
                ->where('i.customer_id', $customerId)
                ->where('i.store_id', $storeId)
                ->where('i.status', '!=', 'cancelled')
                ->selectRaw('
                    MAX(i.created_at) as last_transaction_at,
                    SUM(GREATEST(0, i.amount - i.paid_amount - COALESCE(pd.disc_sum, 0))) as total_debt
                ')
                ->first();

            // 如果结果为 null (即完全没有符合条件的账单，SUM 返回 null)
            if (is_null($stats->total_debt)) {
                return CustomerStoreStat::updateOrCreate(
                    ['customer_id' => $customerId, 'store_id' => $storeId],
                    [
                        'total_debt' => 0.00,
                        // 如果连一笔账单都没有，我们不主动修改最后交易时间，保持原样（或按业务清空）
                        'last_transaction_at' => null
                    ]
                );
            }

            // 物理落表更新缓存
            $stat = CustomerStoreStat::updateOrCreate(
                ['customer_id' => $customerId, 'store_id' => $storeId],
                [
                    'total_debt' => (float) $stats->total_debt,
                    'last_transaction_at' => $stats->last_transaction_at
                ]
            );

            return $stat;
        } catch (\Exception $e) {
            Log::error("Failed to sync customer stats: " . $e->getMessage(), [
                'customer_id' => $customerId,
                'store_id' => $storeId
            ]);
            return null;
        }
    }
}
