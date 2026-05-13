<?php

namespace App\Console\Commands\Maintenance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\InvoiceItem;
use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:cleanup-history 
                            {--months=3 : 保留月数，超过此时间的已结清数据将被清理}
                            {--include=invoices,payments : 清理目标，可选: invoices, payments}
                            {--exclude-stores= : 排除的门店ID，逗号分隔}
                            {--dry-run : 模拟运行，不实际删除}
                            {--export : 删除前导出数据到JSON文件}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理指定月数之前的已结清账单和关联还款数据';

    /**
     * 批处理大小
     */
    protected int $batchSize = 100;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $months = (int) $this->option('months');
        $includeTargets = array_map('trim', explode(',', $this->option('include')));
        $excludeStores = $this->option('exclude-stores')
            ? array_map('intval', explode(',', $this->option('exclude-stores')))
            : [];
        $isDryRun = $this->option('dry-run');
        $shouldExport = $this->option('export');
        $force = $this->option('force');

        if ($months <= 0) {
            $this->error('保留月数必须大于0');
            return Command::FAILURE;
        }

        $cutoffDate = Carbon::now()->subMonths($months);

        $this->info("=== 历史数据清理工具 ===");
        $this->info("截止日期: {$cutoffDate->toDateString()} (超过 {$months} 个月)");
        $this->info("清理目标: " . implode(', ', $includeTargets));
        if (!empty($excludeStores)) {
            $this->info("排除门店: " . implode(', ', $excludeStores));
        }
        if ($isDryRun) {
            $this->warn("[模拟运行模式]");
        }
        $this->newLine();

        // 统计待清理数据
        $stats = $this->calculateStats($cutoffDate, $includeTargets, $excludeStores);

        if ($stats['total'] === 0) {
            $this->info('没有符合条件的数据需要清理。');
            return Command::SUCCESS;
        }

        $this->displayStats($stats);

        // 确认操作
        if (!$isDryRun && !$force) {
            if (!$this->confirm('确认删除以上数据? 此操作不可逆!')) {
                $this->info('操作已取消。');
                return Command::SUCCESS;
            }
        }

        // 导出数据
        if ($shouldExport && !$isDryRun) {
            $this->exportData($cutoffDate, $includeTargets, $excludeStores);
        }

        // 执行清理
        if (!$isDryRun) {
            $this->performCleanup($cutoffDate, $includeTargets, $excludeStores, $stats);
        }

        $this->newLine();
        $this->info('清理完成!');

        return Command::SUCCESS;
    }

    /**
     * 计算待清理数据统计
     */
    protected function calculateStats(Carbon $cutoffDate, array $targets, array $excludeStores): array
    {
        $stats = [
            'invoices' => 0,
            'invoice_items' => 0,
            'payments' => 0,
            'payment_allocations' => 0,
            'payment_discounts' => 0,
            'attachments' => 0,
            'total' => 0,
        ];

        if (in_array('invoices', $targets)) {
            $invoiceQuery = $this->getCleanableInvoicesQuery($cutoffDate, $excludeStores);
            $stats['invoices'] = $invoiceQuery->count();

            // 统计关联数据
            $invoiceIds = $invoiceQuery->pluck('id');
            if ($invoiceIds->isNotEmpty()) {
                $stats['invoice_items'] = InvoiceItem::whereIn('invoice_id', $invoiceIds)->count();
                $stats['payment_allocations'] += PaymentAllocation::whereIn('invoice_id', $invoiceIds)->count();
                $stats['payment_discounts'] += PaymentDiscount::whereIn('invoice_id', $invoiceIds)->count();
                $stats['attachments'] += Attachment::where('attachable_type', Invoice::class)
                    ->whereIn('attachable_id', $invoiceIds)->count();
            }
        }

        if (in_array('payments', $targets)) {
            $paymentQuery = $this->getCleanablePaymentsQuery($cutoffDate, $excludeStores);
            $stats['payments'] = $paymentQuery->count();

            // 统计关联附件
            $paymentIds = $paymentQuery->pluck('id');
            if ($paymentIds->isNotEmpty()) {
                $stats['attachments'] += Attachment::where('attachable_type', Payment::class)
                    ->whereIn('attachable_id', $paymentIds)->count();
            }
        }

        $stats['total'] = $stats['invoices'] + $stats['payments'];

        return $stats;
    }

    /**
     * 获取可清理的账单查询
     */
    protected function getCleanableInvoicesQuery(Carbon $cutoffDate, array $excludeStores)
    {
        $query = Invoice::where('status', 'paid')
            ->where('created_at', '<', $cutoffDate);

        if (!empty($excludeStores)) {
            $query->whereNotIn('store_id', $excludeStores);
        }

        return $query;
    }

    /**
     * 获取可清理的还款查询
     * 只清理已完全分配且关联账单都已结清的还款
     */
    protected function getCleanablePaymentsQuery(Carbon $cutoffDate, array $excludeStores)
    {
        $query = Payment::where('created_at', '<', $cutoffDate)
            ->whereRaw('allocated_amount >= amount') // 已完全分配
            ->whereNotExists(function ($subQuery) {
                // 不存在未结清的关联账单
                $subQuery->select(DB::raw(1))
                    ->from('payment_allocations')
                    ->join('invoices', 'payment_allocations.invoice_id', '=', 'invoices.id')
                    ->whereColumn('payment_allocations.payment_id', 'payments.id')
                    ->where('invoices.status', '!=', 'paid');
            });

        if (!empty($excludeStores)) {
            $query->whereNotIn('store_id', $excludeStores);
        }

        return $query;
    }

    /**
     * 显示统计信息
     */
    protected function displayStats(array $stats): void
    {
        $this->info('待清理数据统计:');
        $this->table(
            ['数据类型', '数量'],
            [
                ['账单 (Invoice)', $stats['invoices']],
                ['账单明细 (InvoiceItem)', $stats['invoice_items']],
                ['还款 (Payment)', $stats['payments']],
                ['还款分配 (PaymentAllocation)', $stats['payment_allocations']],
                ['优惠减免 (PaymentDiscount)', $stats['payment_discounts']],
                ['附件 (Attachment)', $stats['attachments']],
            ]
        );
        $this->newLine();
    }

    /**
     * 导出数据到JSON文件
     */
    protected function exportData(Carbon $cutoffDate, array $targets, array $excludeStores): void
    {
        $this->info('正在导出数据...');

        $exportDir = storage_path('app/maintenance_exports');
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $exportFile = "{$exportDir}/cleanup_export_{$timestamp}.json";

        $exportData = [
            'exported_at' => Carbon::now()->toIso8601String(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'data' => [],
        ];

        if (in_array('invoices', $targets)) {
            $invoices = $this->getCleanableInvoicesQuery($cutoffDate, $excludeStores)
                ->with(['items', 'paymentAllocations', 'discounts', 'attachments'])
                ->get();
            $exportData['data']['invoices'] = $invoices->toArray();
        }

        if (in_array('payments', $targets)) {
            $payments = $this->getCleanablePaymentsQuery($cutoffDate, $excludeStores)
                ->with(['allocations', 'discounts', 'attachments'])
                ->get();
            $exportData['data']['payments'] = $payments->toArray();
        }

        file_put_contents($exportFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("数据已导出到: {$exportFile}");
        $this->newLine();
    }

    /**
     * 执行清理操作
     */
    protected function performCleanup(Carbon $cutoffDate, array $targets, array $excludeStores, array $stats): void
    {
        $this->info('正在执行清理...');

        // 清理账单及关联数据
        if (in_array('invoices', $targets) && $stats['invoices'] > 0) {
            $this->cleanupInvoices($cutoffDate, $excludeStores);
        }

        // 清理还款及关联数据
        if (in_array('payments', $targets) && $stats['payments'] > 0) {
            $this->cleanupPayments($cutoffDate, $excludeStores);
        }
    }

    /**
     * 清理账单数据
     */
    protected function cleanupInvoices(Carbon $cutoffDate, array $excludeStores): void
    {
        $this->info('清理账单数据...');

        $query = $this->getCleanableInvoicesQuery($cutoffDate, $excludeStores);
        $total = $query->count();
        $deleted = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($this->batchSize, function ($invoices) use (&$deleted, $bar) {
            $invoiceIds = $invoices->pluck('id');

            DB::transaction(function () use ($invoiceIds) {
                // 删除关联附件 (会自动清理S3文件，参见Attachment模型的deleting事件)
                Attachment::where('attachable_type', Invoice::class)
                    ->whereIn('attachable_id', $invoiceIds)
                    ->each(function ($attachment) {
                        $attachment->delete();
                    });

                // 删除还款分配
                PaymentAllocation::whereIn('invoice_id', $invoiceIds)->delete();

                // 删除优惠减免
                PaymentDiscount::whereIn('invoice_id', $invoiceIds)->delete();

                // 删除账单明细
                InvoiceItem::whereIn('invoice_id', $invoiceIds)->delete();

                // 删除账单
                Invoice::whereIn('id', $invoiceIds)->delete();
            });

            $deleted += $invoiceIds->count();
            $bar->advance($invoiceIds->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info("已删除 {$deleted} 条账单记录");
    }

    /**
     * 清理还款数据
     */
    protected function cleanupPayments(Carbon $cutoffDate, array $excludeStores): void
    {
        $this->info('清理还款数据...');

        $query = $this->getCleanablePaymentsQuery($cutoffDate, $excludeStores);
        $total = $query->count();
        $deleted = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($this->batchSize, function ($payments) use (&$deleted, $bar) {
            $paymentIds = $payments->pluck('id');

            DB::transaction(function () use ($paymentIds) {
                // 删除关联附件
                Attachment::where('attachable_type', Payment::class)
                    ->whereIn('attachable_id', $paymentIds)
                    ->each(function ($attachment) {
                        $attachment->delete();
                    });

                // 删除还款分配 (账单侧的分配可能已被账单清理删除，这里处理遗留的)
                PaymentAllocation::whereIn('payment_id', $paymentIds)->delete();

                // 删除优惠减免
                PaymentDiscount::whereIn('payment_id', $paymentIds)->delete();

                // 删除还款
                Payment::whereIn('id', $paymentIds)->delete();
            });

            $deleted += $paymentIds->count();
            $bar->advance($paymentIds->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info("已删除 {$deleted} 条还款记录");
    }
}
