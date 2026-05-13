<?php

namespace App\Console\Commands\Maintenance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrphanCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:orphan-check 
                            {--type=all : 检测类型: all, invoice_items, allocations, discounts, attachments}
                            {--fix : 自动修复 (删除孤立数据)}
                            {--dry-run : 模拟运行，不实际删除}
                            {--export : 导出孤立数据报告}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检测并清理失去父级引用的孤立数据';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $shouldFix = $this->option('fix');
        $isDryRun = $this->option('dry-run');
        $shouldExport = $this->option('export');

        $this->info("=== 孤立数据检测工具 ===");
        $this->info("检测类型: {$type}");
        if ($isDryRun) {
            $this->warn("[模拟运行模式]");
        }
        $this->newLine();

        $results = [];
        $types = $type === 'all'
            ? ['invoice_items', 'allocations', 'discounts', 'attachments']
            : [$type];

        foreach ($types as $checkType) {
            $result = $this->checkOrphans($checkType);
            $results[$checkType] = $result;
        }

        // 显示结果
        $this->displayResults($results);

        // 导出报告
        if ($shouldExport) {
            $this->exportReport($results);
        }

        // 修复孤立数据
        if ($shouldFix && !$isDryRun) {
            $totalOrphans = array_sum(array_column($results, 'count'));
            if ($totalOrphans > 0) {
                if ($this->confirm('确认删除以上孤立数据?')) {
                    $this->fixOrphans($results);
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 检测孤立数据
     */
    protected function checkOrphans(string $type): array
    {
        return match ($type) {
            'invoice_items' => $this->checkOrphanInvoiceItems(),
            'allocations' => $this->checkOrphanAllocations(),
            'discounts' => $this->checkOrphanDiscounts(),
            'attachments' => $this->checkOrphanAttachments(),
            default => ['count' => 0, 'ids' => [], 'type' => $type],
        };
    }

    /**
     * 检测孤立的账单明细
     */
    protected function checkOrphanInvoiceItems(): array
    {
        $orphans = InvoiceItem::leftJoin('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.id')
            ->select('invoice_items.*')
            ->get();

        return [
            'type' => 'invoice_items',
            'label' => '账单明细 (InvoiceItem)',
            'count' => $orphans->count(),
            'ids' => $orphans->pluck('id')->toArray(),
            'details' => $orphans->map(fn($item) => [
                'id' => $item->id,
                'invoice_id' => $item->invoice_id,
                'item_name' => $item->item_name,
                'subtotal' => $item->subtotal,
            ])->toArray(),
        ];
    }

    /**
     * 检测孤立的还款分配
     */
    protected function checkOrphanAllocations(): array
    {
        // 检测 payment_id 或 invoice_id 无效的记录
        $orphansByPayment = PaymentAllocation::leftJoin('payments', 'payment_allocations.payment_id', '=', 'payments.id')
            ->whereNull('payments.id')
            ->select('payment_allocations.*')
            ->get();

        $orphansByInvoice = PaymentAllocation::leftJoin('invoices', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.id')
            ->select('payment_allocations.*')
            ->get();

        $allOrphanIds = $orphansByPayment->pluck('id')
            ->merge($orphansByInvoice->pluck('id'))
            ->unique()
            ->values();

        return [
            'type' => 'allocations',
            'label' => '还款分配 (PaymentAllocation)',
            'count' => $allOrphanIds->count(),
            'ids' => $allOrphanIds->toArray(),
            'details' => [
                'orphan_by_payment' => $orphansByPayment->count(),
                'orphan_by_invoice' => $orphansByInvoice->count(),
            ],
        ];
    }

    /**
     * 检测孤立的优惠减免
     */
    protected function checkOrphanDiscounts(): array
    {
        $orphansByPayment = PaymentDiscount::leftJoin('payments', 'payment_discounts.payment_id', '=', 'payments.id')
            ->whereNull('payments.id')
            ->select('payment_discounts.*')
            ->get();

        $orphansByInvoice = PaymentDiscount::leftJoin('invoices', 'payment_discounts.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.id')
            ->select('payment_discounts.*')
            ->get();

        $allOrphanIds = $orphansByPayment->pluck('id')
            ->merge($orphansByInvoice->pluck('id'))
            ->unique()
            ->values();

        return [
            'type' => 'discounts',
            'label' => '优惠减免 (PaymentDiscount)',
            'count' => $allOrphanIds->count(),
            'ids' => $allOrphanIds->toArray(),
            'details' => [
                'orphan_by_payment' => $orphansByPayment->count(),
                'orphan_by_invoice' => $orphansByInvoice->count(),
            ],
        ];
    }

    /**
     * 检测孤立的附件 (多态关联)
     */
    protected function checkOrphanAttachments(): array
    {
        $orphans = collect();

        // 检测关联 Invoice 的孤立附件
        $invoiceOrphans = Attachment::where('attachable_type', Invoice::class)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'attachments.attachable_id');
            })
            ->get();
        $orphans = $orphans->merge($invoiceOrphans);

        // 检测关联 Payment 的孤立附件
        $paymentOrphans = Attachment::where('attachable_type', Payment::class)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.id', 'attachments.attachable_id');
            })
            ->get();
        $orphans = $orphans->merge($paymentOrphans);

        return [
            'type' => 'attachments',
            'label' => '附件 (Attachment)',
            'count' => $orphans->count(),
            'ids' => $orphans->pluck('id')->toArray(),
            'details' => [
                'orphan_invoice_attachments' => $invoiceOrphans->count(),
                'orphan_payment_attachments' => $paymentOrphans->count(),
                'total_file_size' => $orphans->sum('file_size'),
            ],
        ];
    }

    /**
     * 显示检测结果
     */
    protected function displayResults(array $results): void
    {
        $tableData = [];
        $totalOrphans = 0;

        foreach ($results as $result) {
            $tableData[] = [
                $result['label'] ?? $result['type'],
                $result['count'],
                $result['count'] > 0 ? '需要清理' : '正常',
            ];
            $totalOrphans += $result['count'];
        }

        $this->info('检测结果:');
        $this->table(['数据类型', '孤立数量', '状态'], $tableData);
        $this->newLine();

        if ($totalOrphans === 0) {
            $this->info('✓ 未发现孤立数据');
        } else {
            $this->warn("共发现 {$totalOrphans} 条孤立数据");
        }
    }

    /**
     * 导出报告
     */
    protected function exportReport(array $results): void
    {
        $exportDir = storage_path('app/maintenance_exports');
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $exportFile = "{$exportDir}/orphan_report_{$timestamp}.json";

        $reportData = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'results' => $results,
        ];

        file_put_contents($exportFile, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("报告已导出到: {$exportFile}");
    }

    /**
     * 修复孤立数据
     */
    protected function fixOrphans(array $results): void
    {
        $this->info('正在清理孤立数据...');

        foreach ($results as $result) {
            if ($result['count'] === 0) {
                continue;
            }

            $this->info("清理 {$result['label']}...");

            match ($result['type']) {
                'invoice_items' => InvoiceItem::whereIn('id', $result['ids'])->delete(),
                'allocations' => PaymentAllocation::whereIn('id', $result['ids'])->delete(),
                'discounts' => PaymentDiscount::whereIn('id', $result['ids'])->delete(),
                'attachments' => $this->deleteOrphanAttachments($result['ids']),
                default => null,
            };

            $this->info("  已删除 {$result['count']} 条记录");
        }

        $this->newLine();
        $this->info('孤立数据清理完成!');
    }

    /**
     * 删除孤立附件 (会触发S3文件删除)
     */
    protected function deleteOrphanAttachments(array $ids): void
    {
        Attachment::whereIn('id', $ids)->each(function ($attachment) {
            $attachment->delete(); // 触发 deleting 事件，自动清理 S3 文件
        });
    }
}
