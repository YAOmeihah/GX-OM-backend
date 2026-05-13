<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Store;
use App\Services\CustomerStatsService;
use Illuminate\Support\Facades\DB;

class SyncCustomerStoreStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-customer-stats {--customer= : 特定客户ID} {--store= : 特定门店ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '一次性彻底清洗和同步客户在各门店下的欠款和交易统计数据到 customer_store_stats 表';

    /**
     * Execute the console command.
     */
    public function handle(CustomerStatsService $statsService)
    {
        $this->info('准备开始清洗并同步客户统计数据...');

        $customerId = $this->option('customer');
        $storeId = $this->option('store');

        $customers = Customer::query()
            ->when($customerId, fn($q) => $q->where('id', $customerId))
            ->get();

        $stores = Store::query()
            ->when($storeId, fn($q) => $q->where('id', $storeId))
            ->get();

        if ($customers->isEmpty() || $stores->isEmpty()) {
            $this->warn('未找到匹配的客户或门店数据。');
            return;
        }

        $totalCount = $customers->count() * $stores->count();
        $bar = $this->output->createProgressBar($totalCount);

        $bar->start();

        foreach ($customers as $customer) {
            foreach ($stores as $store) {
                // 利用写好的 Service 直接完成结算和入库
                $statsService->syncCustomerStoreStats($customer->id, $store->id);
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info('所有客户在对应门店的统计(欠款、交易时间)数据清洗同步完毕！');
    }
}
