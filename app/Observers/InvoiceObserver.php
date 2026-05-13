<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\CustomerStatsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Observer
 * 
 * 自动监听 Invoice 模型的 created、updated、deleted 事件
 * 在账单发生变化时，自动更新客户在对应门店的统计信息（总欠款、最后交易时间）
 * 
 * 特性：
 * - 事务安全：使用 DB::afterCommit() 确保在事务提交后才执行统计更新
 * - 防抖机制：同一请求内的多次更新只执行一次，避免重复计算
 * - 性能优化：批量更新，减少数据库操作
 */
class InvoiceObserver
{
    /**
     * 静态数组：收集待更新的客户和门店
     * 格式：['customer_id' => int, 'store_id' => int]
     */
    private static $pendingUpdates = [];

    /**
     * 静态布尔值：确保只注册一次 shutdown 回调
     */
    private static $shutdownRegistered = false;

    /**
     * 监听账单创建事件
     * 
     * 当新账单创建时，客户的总欠款增加，需要更新统计
     */
    public function created(Invoice $invoice): void
    {
        $this->scheduleStatsUpdate($invoice->customer_id, $invoice->store_id);
    }

    /**
     * 监听账单更新事件
     *
     * 只有当 paid_amount 或 status 发生变化时，才需要更新统计
     * 使用 wasChanged() 检测字段是否在上次保存时发生变化
     * 注意：updated 事件在模型保存后触发，此时 isDirty() 会返回 false
     */
    public function updated(Invoice $invoice): void
    {
        // 检查 paid_amount 或 status 是否在上次保存时发生变化
        if ($invoice->wasChanged(['paid_amount', 'status'])) {
            $this->scheduleStatsUpdate($invoice->customer_id, $invoice->store_id);
        }
    }

    /**
     * 监听账单删除事件
     * 
     * 当账单删除时，客户的总欠款减少，需要更新统计
     */
    public function deleted(Invoice $invoice): void
    {
        $this->scheduleStatsUpdate($invoice->customer_id, $invoice->store_id);
    }

    /**
     * 将客户和门店添加到待更新列表
     * 
     * 使用 DB::afterCommit() 确保在事务提交后才执行统计更新
     * 使用静态数组收集待更新的客户，在请求结束时批量更新
     * 
     * @param int $customerId 客户 ID
     * @param int $storeId 门店 ID
     */
    private function scheduleStatsUpdate(int $customerId, int $storeId): void
    {
        // 使用 afterCommit 确保在事务提交后才执行统计更新
        DB::afterCommit(function () use ($customerId, $storeId) {
            // 将客户和门店添加到待更新列表
            self::$pendingUpdates[] = [
                'customer_id' => $customerId,
                'store_id' => $storeId
            ];

            // 如果尚未注册 shutdown 函数，则注册
            if (!self::$shutdownRegistered) {
                // 在长驻进程(如 Queue Worker) 中，shutdown_function 不会触发且会导致内存泄漏
                // 因此支持 terminating 钩子，并在控制台直接执行
                if (app()->runningInConsole() && !app()->runningUnitTests()) {
                    // 队列等 console 环境尽早执行
                    self::processPendingUpdates();
                } else {
                    app()->terminating([self::class, 'processPendingUpdates']);
                    self::$shutdownRegistered = true;
                }
            }
        });
    }

    /**
     * 批量更新所有待更新的客户统计
     * 
     * 在请求结束时执行，对所有待更新的客户进行去重后批量更新
     * 使用 try-catch 确保一个客户的更新失败不影响其他客户
     */
    public static function processPendingUpdates(): void
    {
        if (empty(self::$pendingUpdates)) {
            return;
        }

        // 去重：确保同一 (customer_id, store_id) 组合只更新一次
        $uniqueUpdates = [];
        foreach (self::$pendingUpdates as $update) {
            $key = $update['customer_id'] . '_' . $update['store_id'];
            $uniqueUpdates[$key] = $update;
        }

        // 批量更新所有待更新的客户统计
        $statsService = app(CustomerStatsService::class);

        foreach ($uniqueUpdates as $update) {
            try {
                $statsService->syncCustomerStoreStats(
                    $update['customer_id'],
                    $update['store_id']
                );
            } catch (\Exception $e) {
                // 记录错误日志，但不影响其他客户的更新
                Log::error('Failed to sync customer stats in Observer', [
                    'customer_id' => $update['customer_id'],
                    'store_id' => $update['store_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 清空待更新列表
        self::$pendingUpdates = [];
        self::$shutdownRegistered = false;
    }
}

