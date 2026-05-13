<?php

namespace App\Console\Commands\Maintenance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegrityCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:integrity-check 
                            {--type=all : 检查类型: all, invoice_amount, paid_amount, invoice_status, payment_allocation}
                            {--fix : 自动修复不一致的数据}
                            {--store= : 限定门店ID}
                            {--report : 生成详细报告}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检测并修复数据完整性问题 (金额一致性、状态一致性等)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $shouldFix = $this->option('fix');
        $storeId = $this->option('store');
        $shouldReport = $this->option('report');

        $this->info("=== 数据完整性检查工具 ===");
        $this->info("检查类型: {$type}");
        if ($storeId) {
            $this->info("限定门店: {$storeId}");
        }
        $this->newLine();

        $results = [];
        $types = $type === 'all'
            ? ['invoice_amount', 'paid_amount', 'invoice_status', 'payment_allocation']
            : [$type];

        foreach ($types as $checkType) {
            $result = $this->runCheck($checkType, $storeId);
            $results[$checkType] = $result;
            $this->displayCheckResult($result);
        }

        // 生成报告
        if ($shouldReport) {
            $this->generateReport($results);
        }

        // 自动修复
        if ($shouldFix) {
            $totalIssues = array_sum(array_column($results, 'count'));
            if ($totalIssues > 0) {
                if ($this->confirm('确认自动修复以上问题?')) {
                    $this->fixIssues($results, $storeId);
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 运行检查
     */
    protected function runCheck(string $type, ?int $storeId): array
    {
        return match ($type) {
            'invoice_amount' => $this->checkInvoiceAmount($storeId),
            'paid_amount' => $this->checkPaidAmount($storeId),
            'invoice_status' => $this->checkInvoiceStatus($storeId),
            'payment_allocation' => $this->checkPaymentAllocation($storeId),
            default => ['type' => $type, 'label' => $type, 'count' => 0, 'issues' => []],
        };
    }

    /**
     * 检查账单金额与明细项小计是否一致
     */
    protected function checkInvoiceAmount(?int $storeId): array
    {
        $query = Invoice::query();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $issues = [];
        $query->with('items')->chunk(100, function ($invoices) use (&$issues) {
            foreach ($invoices as $invoice) {
                if ($invoice->items->isEmpty()) {
                    continue; // 没有明细的账单跳过
                }

                $itemsTotal = $invoice->items->sum('subtotal');
                $diff = abs($invoice->amount - $itemsTotal);

                if ($diff > 0.01) { // 允许0.01的浮点误差
                    $issues[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_amount' => $invoice->amount,
                        'calculated_amount' => $itemsTotal,
                        'difference' => $invoice->amount - $itemsTotal,
                    ];
                }
            }
        });

        return [
            'type' => 'invoice_amount',
            'label' => '账单金额一致性',
            'description' => '检查账单总金额是否等于明细项小计之和',
            'count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * 检查已付金额与分配记录是否一致
     */
    protected function checkPaidAmount(?int $storeId): array
    {
        $query = Invoice::query();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $issues = [];
        $query->chunk(100, function ($invoices) use (&$issues) {
            foreach ($invoices as $invoice) {
                $allocatedTotal = PaymentAllocation::where('invoice_id', $invoice->id)->sum('amount');
                $diff = abs($invoice->paid_amount - $allocatedTotal);

                if ($diff > 0.01) {
                    $issues[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_paid_amount' => $invoice->paid_amount,
                        'calculated_paid_amount' => $allocatedTotal,
                        'difference' => $invoice->paid_amount - $allocatedTotal,
                    ];
                }
            }
        });

        return [
            'type' => 'paid_amount',
            'label' => '已付金额一致性',
            'description' => '检查账单已付金额是否等于还款分配记录之和',
            'count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * 检查账单状态是否与金额匹配
     */
    protected function checkInvoiceStatus(?int $storeId): array
    {
        $query = Invoice::query();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $issues = [];
        $query->chunk(100, function ($invoices) use (&$issues) {
            foreach ($invoices as $invoice) {
                $totalPaidAndDiscounted = $invoice->paid_amount + $invoice->total_discount_amount;
                $expectedStatus = $this->calculateExpectedStatus($invoice, $totalPaidAndDiscounted);

                if ($invoice->status !== $expectedStatus) {
                    $issues[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_status' => $invoice->status,
                        'expected_status' => $expectedStatus,
                        'amount' => $invoice->amount,
                        'paid_amount' => $invoice->paid_amount,
                        'discount_amount' => $invoice->total_discount_amount,
                    ];
                }
            }
        });

        return [
            'type' => 'invoice_status',
            'label' => '账单状态一致性',
            'description' => '检查账单状态是否与已付金额匹配',
            'count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * 计算期望的账单状态
     */
    protected function calculateExpectedStatus(Invoice $invoice, float $totalPaidAndDiscounted): string
    {
        if ($totalPaidAndDiscounted >= $invoice->amount) {
            return 'paid';
        } elseif ($invoice->paid_amount > 0 || $invoice->total_discount_amount > 0) {
            return 'partially_paid';
        } elseif ($invoice->due_date && Carbon::parse($invoice->due_date)->isPast()) {
            return 'overdue';
        } else {
            return 'unpaid';
        }
    }

    /**
     * 检查还款分配金额一致性
     */
    protected function checkPaymentAllocation(?int $storeId): array
    {
        $query = Payment::query();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $issues = [];
        $query->chunk(100, function ($payments) use (&$issues) {
            foreach ($payments as $payment) {
                $allocatedTotal = PaymentAllocation::where('payment_id', $payment->id)->sum('amount');
                $diff = abs($payment->allocated_amount - $allocatedTotal);

                if ($diff > 0.01) {
                    $issues[] = [
                        'payment_id' => $payment->id,
                        'payment_number' => $payment->payment_number,
                        'current_allocated' => $payment->allocated_amount,
                        'calculated_allocated' => $allocatedTotal,
                        'difference' => $payment->allocated_amount - $allocatedTotal,
                    ];
                }
            }
        });

        return [
            'type' => 'payment_allocation',
            'label' => '还款分配一致性',
            'description' => '检查还款已分配金额是否等于分配记录之和',
            'count' => count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * 显示检查结果
     */
    protected function displayCheckResult(array $result): void
    {
        $status = $result['count'] === 0 ? '✓ 正常' : "⚠ 发现 {$result['count']} 个问题";
        $this->info("[{$result['label']}] {$status}");

        if ($result['count'] > 0 && count($result['issues']) <= 5) {
            foreach ($result['issues'] as $issue) {
                $this->warn("  - " . json_encode($issue, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($result['count'] > 5) {
            $this->warn("  (仅显示前5条，共 {$result['count']} 条)");
            foreach (array_slice($result['issues'], 0, 5) as $issue) {
                $this->warn("  - " . json_encode($issue, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
     * 生成报告
     */
    protected function generateReport(array $results): void
    {
        $exportDir = storage_path('app/maintenance_exports');
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $exportFile = "{$exportDir}/integrity_report_{$timestamp}.json";

        $reportData = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'results' => $results,
        ];

        file_put_contents($exportFile, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info("报告已导出到: {$exportFile}");
    }

    /**
     * 修复问题
     */
    protected function fixIssues(array $results, ?int $storeId): void
    {
        $this->info('正在修复问题...');

        foreach ($results as $result) {
            if ($result['count'] === 0) {
                continue;
            }

            $this->info("修复 {$result['label']}...");

            match ($result['type']) {
                'invoice_amount' => $this->fixInvoiceAmount($result['issues']),
                'paid_amount' => $this->fixPaidAmount($result['issues']),
                'invoice_status' => $this->fixInvoiceStatus($result['issues']),
                'payment_allocation' => $this->fixPaymentAllocation($result['issues']),
                default => null,
            };

            $this->info("  已修复 {$result['count']} 条记录");
        }

        $this->newLine();
        $this->info('修复完成!');
    }

    /**
     * 修复账单金额
     */
    protected function fixInvoiceAmount(array $issues): void
    {
        foreach ($issues as $issue) {
            Invoice::where('id', $issue['invoice_id'])
                ->update(['amount' => $issue['calculated_amount']]);
        }
    }

    /**
     * 修复已付金额
     */
    protected function fixPaidAmount(array $issues): void
    {
        foreach ($issues as $issue) {
            Invoice::where('id', $issue['invoice_id'])
                ->update(['paid_amount' => $issue['calculated_paid_amount']]);
        }
    }

    /**
     * 修复账单状态
     */
    protected function fixInvoiceStatus(array $issues): void
    {
        foreach ($issues as $issue) {
            Invoice::where('id', $issue['invoice_id'])
                ->update(['status' => $issue['expected_status']]);
        }
    }

    /**
     * 修复还款分配金额
     */
    protected function fixPaymentAllocation(array $issues): void
    {
        foreach ($issues as $issue) {
            Payment::where('id', $issue['payment_id'])
                ->update(['allocated_amount' => $issue['calculated_allocated']]);
        }
    }
}
