<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShareToken;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MaintenanceScanService
{
    /**
     * 扫描结果缓存 (存储在缓存或会话中)
     */
    protected array $scanCache = [];

    /**
     * 扫描历史清理数据
     */
    public function scanHistoryCleanup(array $options): array
    {
        $months = $options['months'] ?? 3;
        $targets = $options['targets'] ?? ['invoices', 'payments'];
        $excludeStores = $options['exclude_stores'] ?? [];
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 50;

        $cutoffDate = Carbon::now()->subMonths($months);
        $scanId = Str::uuid()->toString();

        $items = collect();
        $summary = [
            'invoices' => 0,
            'invoice_items' => 0,
            'payments' => 0,
            'payment_allocations' => 0,
            'payment_discounts' => 0,
            'attachments' => 0,
        ];

        // 扫描账单
        if (in_array('invoices', $targets)) {
            $invoiceQuery = Invoice::where('status', 'paid')
                ->where('created_at', '<', $cutoffDate);

            if (! empty($excludeStores)) {
                $invoiceQuery->whereNotIn('store_id', $excludeStores);
            }

            $summary['invoices'] = $invoiceQuery->count();

            $invoiceIds = $invoiceQuery->pluck('id');
            if ($invoiceIds->isNotEmpty()) {
                $summary['invoice_items'] = InvoiceItem::whereIn('invoice_id', $invoiceIds)->count();
                $summary['payment_allocations'] += PaymentAllocation::whereIn('invoice_id', $invoiceIds)->count();
                $summary['payment_discounts'] += PaymentDiscount::whereIn('invoice_id', $invoiceIds)->count();
                $summary['attachments'] += Attachment::where('attachable_type', Invoice::class)
                    ->whereIn('attachable_id', $invoiceIds)->count();
            }

            // 获取分页数据（使用 withCount 避免 N+1 查询）
            $invoices = $invoiceQuery->with(['customer:id,name', 'store:id,name'])
                ->withCount('items as related_items_count')
                ->select(['id', 'invoice_number', 'customer_id', 'store_id', 'amount', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($invoices as $invoice) {
                $items->push([
                    'id' => $invoice->id,
                    'type' => 'invoice',
                    'identifier' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer?->name ?? '未知',
                    'store_name' => $invoice->store?->name ?? '未知',
                    'amount' => $invoice->amount,
                    'created_at' => $invoice->created_at->toDateString(),
                    'related_items' => $invoice->related_items_count,
                    'can_delete' => true,
                ]);
            }
        }

        // 扫描还款
        if (in_array('payments', $targets)) {
            $paymentQuery = Payment::where('created_at', '<', $cutoffDate)
                ->whereRaw('allocated_amount >= amount')
                ->whereRaw('allocated_amount > 0') // 确保有分配记录，避免误删未使用的还款
                ->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('payment_allocations')
                        ->join('invoices', 'payment_allocations.invoice_id', '=', 'invoices.id')
                        ->whereColumn('payment_allocations.payment_id', 'payments.id')
                        ->where('invoices.status', '!=', 'paid');
                });

            if (! empty($excludeStores)) {
                $paymentQuery->whereNotIn('store_id', $excludeStores);
            }

            $summary['payments'] = $paymentQuery->count();

            $paymentIds = $paymentQuery->pluck('id');
            if ($paymentIds->isNotEmpty()) {
                $summary['attachments'] += Attachment::where('attachable_type', Payment::class)
                    ->whereIn('attachable_id', $paymentIds)->count();
            }

            // 获取分页数据（使用 withCount 避免 N+1 查询）
            $payments = $paymentQuery->with(['customer:id,name', 'store:id,name'])
                ->withCount('allocations as related_items_count')
                ->select(['id', 'payment_number', 'customer_id', 'store_id', 'amount', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($payments as $payment) {
                $items->push([
                    'id' => $payment->id,
                    'type' => 'payment',
                    'identifier' => $payment->payment_number ?? "P-{$payment->id}",
                    'customer_name' => $payment->customer?->name ?? '未知',
                    'store_name' => $payment->store?->name ?? '未知',
                    'amount' => $payment->amount,
                    'created_at' => $payment->created_at->toDateString(),
                    'related_items' => $payment->related_items_count,
                    'can_delete' => true,
                ]);
            }
        }

        // 分页处理
        $totalItems = $items->count();
        $paginatedItems = $items->forPage($page, $perPage)->values();

        // 缓存扫描结果
        cache()->put("maintenance_scan:{$scanId}", [
            'type' => 'history_cleanup',
            'options' => $options,
            'cutoff_date' => $cutoffDate->toDateString(),
            'items' => $items->toArray(),
        ], now()->addHours(1));

        return [
            'scan_id' => $scanId,
            'type' => 'history_cleanup',
            'scanned_at' => Carbon::now()->toIso8601String(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'summary' => $summary,
            'items' => $paginatedItems,
            'total_items' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 扫描孤立数据
     */
    public function scanOrphanData(array $options): array
    {
        $types = $options['types'] ?? ['invoice_items', 'payment_allocations', 'attachments'];
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 50;

        $scanId = Str::uuid()->toString();
        $items = collect();
        $summary = [];

        // 检测孤立的账单明细
        if (in_array('invoice_items', $types)) {
            $orphanItems = InvoiceItem::leftJoin('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->whereNull('invoices.id')
                ->select('invoice_items.*')
                ->get();

            $summary['invoice_items'] = $orphanItems->count();

            foreach ($orphanItems as $item) {
                $items->push([
                    'id' => $item->id,
                    'type' => 'invoice_item',
                    'identifier' => $item->item_name,
                    'orphan_reason' => '关联账单已删除',
                    'invoice_id' => $item->invoice_id,
                    'amount' => $item->subtotal,
                    'created_at' => $item->created_at?->toDateString(),
                    'can_delete' => true,
                ]);
            }
        }

        // 检测孤立的还款分配
        if (in_array('payment_allocations', $types)) {
            $orphanAllocations = PaymentAllocation::leftJoin('payments', 'payment_allocations.payment_id', '=', 'payments.id')
                ->leftJoin('invoices', 'payment_allocations.invoice_id', '=', 'invoices.id')
                ->where(function ($q) {
                    $q->whereNull('payments.id')->orWhereNull('invoices.id');
                })
                ->select('payment_allocations.*')
                ->get();

            $summary['payment_allocations'] = $orphanAllocations->count();

            foreach ($orphanAllocations as $alloc) {
                $items->push([
                    'id' => $alloc->id,
                    'type' => 'payment_allocation',
                    'identifier' => "分配 #{$alloc->id}",
                    'orphan_reason' => '关联还款或账单已删除',
                    'payment_id' => $alloc->payment_id,
                    'invoice_id' => $alloc->invoice_id,
                    'amount' => $alloc->amount,
                    'created_at' => $alloc->created_at?->toDateString(),
                    'can_delete' => true,
                ]);
            }
        }

        // 检测孤立的附件
        if (in_array('attachments', $types)) {
            $orphanAttachments = Attachment::where(function ($q) {
                $q->where('attachable_type', Invoice::class)
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))->from('invoices')
                            ->whereColumn('invoices.id', 'attachments.attachable_id');
                    });
            })->orWhere(function ($q) {
                $q->where('attachable_type', Payment::class)
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))->from('payments')
                            ->whereColumn('payments.id', 'attachments.attachable_id');
                    });
            })->get();

            $summary['attachments'] = $orphanAttachments->count();

            foreach ($orphanAttachments as $attach) {
                $items->push([
                    'id' => $attach->id,
                    'type' => 'attachment',
                    'identifier' => $attach->original_filename ?? $attach->filename,
                    'orphan_reason' => '关联实体已删除',
                    'attachable_type' => class_basename($attach->attachable_type),
                    'attachable_id' => $attach->attachable_id,
                    'file_size' => $attach->file_size,
                    'created_at' => $attach->created_at?->toDateString(),
                    'can_delete' => true,
                ]);
            }
        }

        // 分页处理
        $totalItems = $items->count();
        $paginatedItems = $items->forPage($page, $perPage)->values();

        // 缓存扫描结果
        cache()->put("maintenance_scan:{$scanId}", [
            'type' => 'orphan_check',
            'options' => $options,
            'items' => $items->toArray(),
        ], now()->addHours(1));

        return [
            'scan_id' => $scanId,
            'type' => 'orphan_check',
            'scanned_at' => Carbon::now()->toIso8601String(),
            'summary' => $summary,
            'items' => $paginatedItems,
            'total_items' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 扫描完整性问题
     */
    public function scanIntegrityIssues(array $options): array
    {
        $types = $options['types'] ?? ['invoice_amount', 'invoice_status', 'payment_allocation'];
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 50;

        $scanId = Str::uuid()->toString();
        $items = collect();
        $summary = [];

        // 检测账单金额不一致
        if (in_array('invoice_amount', $types)) {
            $mismatchInvoices = Invoice::select('invoices.*')
                ->selectRaw('COALESCE(SUM(invoice_items.subtotal), 0) as calculated_amount')
                ->leftJoin('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->groupBy('invoices.id')
                ->havingRaw('invoices.amount != COALESCE(SUM(invoice_items.subtotal), 0)')
                ->get();

            $summary['invoice_amount_mismatch'] = $mismatchInvoices->count();

            foreach ($mismatchInvoices as $invoice) {
                $items->push([
                    'id' => $invoice->id,
                    'type' => 'invoice_amount_mismatch',
                    'identifier' => $invoice->invoice_number,
                    'issue' => "金额不一致: 记录={$invoice->amount}, 计算={$invoice->calculated_amount}",
                    'current_value' => $invoice->amount,
                    'expected_value' => $invoice->calculated_amount,
                    'can_fix' => true,
                ]);
            }
        }

        // 检测账单状态不一致（考虑优惠减免）
        if (in_array('invoice_status', $types)) {
            // 使用子查询计算已结算金额 = paid_amount + 优惠减免总额
            $statusIssues = Invoice::select('invoices.*')
                ->selectRaw('(invoices.paid_amount + COALESCE((SELECT SUM(discount_amount) FROM payment_discounts WHERE payment_discounts.invoice_id = invoices.id), 0)) as settled_amount')
                ->havingRaw('
                    (settled_amount >= invoices.amount AND invoices.amount > 0 AND invoices.status != "paid")
                    OR (settled_amount > 0 AND settled_amount < invoices.amount AND invoices.status != "partially_paid")
                    OR (settled_amount = 0 AND invoices.status NOT IN ("unpaid", "cancelled"))
                ')
                ->get();

            $summary['invoice_status_mismatch'] = $statusIssues->count();

            foreach ($statusIssues as $invoice) {
                $expectedStatus = $this->calculateExpectedStatus($invoice);
                $items->push([
                    'id' => $invoice->id,
                    'type' => 'invoice_status_mismatch',
                    'identifier' => $invoice->invoice_number,
                    'issue' => "状态不一致: 当前={$invoice->status}, 应为={$expectedStatus}",
                    'current_value' => $invoice->status,
                    'expected_value' => $expectedStatus,
                    'can_fix' => true,
                ]);
            }
        }

        // 检测分配金额不一致
        if (in_array('payment_allocation', $types)) {
            // 检查还款的 allocated_amount 与实际分配不一致
            $paymentMismatches = Payment::select('payments.*')
                ->selectRaw('COALESCE(SUM(payment_allocations.amount), 0) as actual_allocated')
                ->leftJoin('payment_allocations', 'payments.id', '=', 'payment_allocations.payment_id')
                ->groupBy('payments.id')
                ->havingRaw('payments.allocated_amount != COALESCE(SUM(payment_allocations.amount), 0)')
                ->get();

            $summary['payment_allocation_mismatch'] = $paymentMismatches->count();

            foreach ($paymentMismatches as $payment) {
                $items->push([
                    'id' => $payment->id,
                    'type' => 'payment_allocation_mismatch',
                    'entity_type' => 'payment',
                    'identifier' => $payment->payment_number ?? "P-{$payment->id}",
                    'issue' => "还款分配金额不一致: 记录={$payment->allocated_amount}, 实际={$payment->actual_allocated}",
                    'current_value' => $payment->allocated_amount,
                    'expected_value' => $payment->actual_allocated,
                    'can_fix' => true,
                ]);
            }

            // 检查账单的 paid_amount 与实际分配不一致
            $invoiceMismatches = Invoice::select('invoices.*')
                ->selectRaw('COALESCE(SUM(payment_allocations.amount), 0) as actual_paid')
                ->leftJoin('payment_allocations', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->groupBy('invoices.id')
                ->havingRaw('invoices.paid_amount != COALESCE(SUM(payment_allocations.amount), 0)')
                ->get();

            $summary['invoice_paid_mismatch'] = $invoiceMismatches->count();

            foreach ($invoiceMismatches as $invoice) {
                $items->push([
                    'id' => $invoice->id,
                    'type' => 'payment_allocation_mismatch',
                    'entity_type' => 'invoice',
                    'identifier' => $invoice->invoice_number,
                    'issue' => "账单已付金额不一致: 记录={$invoice->paid_amount}, 实际={$invoice->actual_paid}",
                    'current_value' => $invoice->paid_amount,
                    'expected_value' => $invoice->actual_paid,
                    'can_fix' => true,
                ]);
            }
        }

        // 分页处理
        $totalItems = $items->count();
        $paginatedItems = $items->forPage($page, $perPage)->values();

        // 缓存扫描结果
        cache()->put("maintenance_scan:{$scanId}", [
            'type' => 'integrity_check',
            'options' => $options,
            'items' => $items->toArray(),
        ], now()->addHours(1));

        return [
            'scan_id' => $scanId,
            'type' => 'integrity_check',
            'scanned_at' => Carbon::now()->toIso8601String(),
            'summary' => $summary,
            'items' => $paginatedItems,
            'total_items' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 关键操作类型 (保留更长时间)
     */
    protected array $criticalActions = [
        'delete',
        'discount',
        'revoke_allocation',
    ];

    /**
     * 扫描审计日志清理
     */
    public function scanAuditLogs(array $options): array
    {
        $normalDays = $options['normal_days'] ?? 90;
        $criticalDays = $options['critical_days'] ?? 365;
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 50;

        $normalCutoffDate = Carbon::now()->subDays($normalDays);
        $criticalCutoffDate = Carbon::now()->subDays($criticalDays);
        $scanId = Str::uuid()->toString();

        // P2优化：只统计数量，不全量加载
        $normalCount = AuditLog::where('created_at', '<', $normalCutoffDate)
            ->whereNotIn('action', $this->criticalActions)
            ->count();

        $criticalCount = AuditLog::where('created_at', '<', $criticalCutoffDate)
            ->whereIn('action', $this->criticalActions)
            ->count();

        $summary = [
            'normal_logs' => $normalCount,
            'critical_logs' => $criticalCount,
        ];

        $totalItems = $normalCount + $criticalCount;

        // P2优化：分页查询预览数据
        $items = collect();
        $offset = ($page - 1) * $perPage;
        $remaining = $perPage;

        // 优先显示普通日志
        if ($offset < $normalCount) {
            $normalLogs = AuditLog::where('created_at', '<', $normalCutoffDate)
                ->whereNotIn('action', $this->criticalActions)
                ->select(['id', 'action', 'auditable_type', 'auditable_label', 'user_name', 'description', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->skip($offset)
                ->take($remaining)
                ->get();

            foreach ($normalLogs as $log) {
                $items->push([
                    'id' => $log->id,
                    'type' => 'audit_log',
                    'log_type' => 'normal',
                    'identifier' => "日志 #{$log->id}",
                    'action' => $log->action,
                    'action_label' => AuditLog::ACTION_LABELS[$log->action] ?? $log->action,
                    'target' => $log->auditable_label ?? class_basename($log->auditable_type ?? ''),
                    'user_name' => $log->user_name,
                    'description' => $log->description,
                    'created_at' => $log->created_at->toDateString(),
                    'can_delete' => true,
                ]);
            }

            $remaining -= $normalLogs->count();
        }

        // 如果还有剩余空间，显示关键日志
        if ($remaining > 0) {
            $criticalOffset = max(0, $offset - $normalCount);
            $criticalLogs = AuditLog::where('created_at', '<', $criticalCutoffDate)
                ->whereIn('action', $this->criticalActions)
                ->select(['id', 'action', 'auditable_type', 'auditable_label', 'user_name', 'description', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->skip($criticalOffset)
                ->take($remaining)
                ->get();

            foreach ($criticalLogs as $log) {
                $items->push([
                    'id' => $log->id,
                    'type' => 'audit_log',
                    'log_type' => 'critical',
                    'identifier' => "日志 #{$log->id}",
                    'action' => $log->action,
                    'action_label' => AuditLog::ACTION_LABELS[$log->action] ?? $log->action,
                    'target' => $log->auditable_label ?? class_basename($log->auditable_type ?? ''),
                    'user_name' => $log->user_name,
                    'description' => $log->description,
                    'created_at' => $log->created_at->toDateString(),
                    'can_delete' => true,
                ]);
            }
        }

        // 缓存扫描条件（而非全部数据）
        cache()->put("maintenance_scan:{$scanId}", [
            'type' => 'audit_cleanup',
            'options' => $options,
            'normal_cutoff_date' => $normalCutoffDate->toDateString(),
            'critical_cutoff_date' => $criticalCutoffDate->toDateString(),
            'normal_count' => $normalCount,
            'critical_count' => $criticalCount,
            // 不再缓存全部 items，只缓存条件用于删除时重新查询
        ], now()->addHours(1));

        return [
            'scan_id' => $scanId,
            'type' => 'audit_cleanup',
            'scanned_at' => Carbon::now()->toIso8601String(),
            'normal_cutoff_date' => $normalCutoffDate->toDateString(),
            'critical_cutoff_date' => $criticalCutoffDate->toDateString(),
            'summary' => $summary,
            'items' => $items,
            'total_items' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 执行清理操作
     */
    public function executeCleanup(string $scanId, array $selectedIds = [], bool $exportBeforeDelete = false): array
    {
        $scanData = cache()->get("maintenance_scan:{$scanId}");

        if (! $scanData) {
            throw new \InvalidArgumentException('扫描结果已过期，请重新扫描');
        }

        $deleted = [
            'invoices' => 0,
            'invoice_items' => 0,
            'payments' => 0,
            'payment_allocations' => 0,
            'payment_discounts' => 0,
            'attachments' => 0,
            'audit_logs' => 0,
        ];

        // P1优化：审计日志使用批量删除
        if ($scanData['type'] === 'audit_cleanup') {
            return $this->executeAuditLogCleanup($scanData, $selectedIds, $exportBeforeDelete, $deleted);
        }

        // 其他类型使用原有逻辑
        $allItems = collect($scanData['items'] ?? []);

        // 如果指定了选定项，则只处理选定的
        if (! empty($selectedIds)) {
            $allItems = $allItems->filter(fn ($item) => in_array($item['id'], $selectedIds));
        }

        $exportFile = null;
        if ($exportBeforeDelete) {
            $exportFile = $this->exportData($scanData['type'], $allItems);
        }

        DB::transaction(function () use ($allItems, $scanData, &$deleted) {
            foreach ($allItems as $item) {
                $this->deleteItem($item, $scanData['type'], $deleted);
            }
        });

        // 清除缓存
        cache()->forget("maintenance_scan:{$scanId}");

        return [
            'execution_id' => Str::uuid()->toString(),
            'status' => 'completed',
            'deleted' => $deleted,
            'export_file' => $exportFile ?? null,
            'executed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * P1优化：批量删除审计日志
     */
    protected function executeAuditLogCleanup(array $scanData, array $selectedIds, bool $exportBeforeDelete, array &$deleted): array
    {
        $options = $scanData['options'];
        $normalDays = $options['normal_days'] ?? 90;
        $criticalDays = $options['critical_days'] ?? 365;

        $normalCutoffDate = Carbon::now()->subDays($normalDays);
        $criticalCutoffDate = Carbon::now()->subDays($criticalDays);

        $exportFile = null;

        DB::transaction(function () use ($normalCutoffDate, $criticalCutoffDate, $selectedIds, $exportBeforeDelete, &$deleted, &$exportFile) {
            // 如果选择了特定ID，只删除选中的
            if (! empty($selectedIds)) {
                if ($exportBeforeDelete) {
                    $logsToExport = AuditLog::whereIn('id', $selectedIds)->get();
                    $exportFile = $this->exportData('audit_cleanup', collect($logsToExport->toArray()));
                }
                $deleted['audit_logs'] = AuditLog::whereIn('id', $selectedIds)->delete();
            } else {
                // 批量删除所有符合条件的日志
                if ($exportBeforeDelete) {
                    $allLogs = collect();

                    // 导出普通日志
                    AuditLog::where('created_at', '<', $normalCutoffDate)
                        ->whereNotIn('action', $this->criticalActions)
                        ->chunk(1000, function ($logs) use (&$allLogs) {
                            $allLogs = $allLogs->merge($logs->toArray());
                        });

                    // 导出关键日志
                    AuditLog::where('created_at', '<', $criticalCutoffDate)
                        ->whereIn('action', $this->criticalActions)
                        ->chunk(1000, function ($logs) use (&$allLogs) {
                            $allLogs = $allLogs->merge($logs->toArray());
                        });

                    if ($allLogs->isNotEmpty()) {
                        $exportFile = $this->exportData('audit_cleanup', $allLogs);
                    }
                }

                // 批量删除普通日志
                $normalDeleted = AuditLog::where('created_at', '<', $normalCutoffDate)
                    ->whereNotIn('action', $this->criticalActions)
                    ->delete();

                // 批量删除关键日志
                $criticalDeleted = AuditLog::where('created_at', '<', $criticalCutoffDate)
                    ->whereIn('action', $this->criticalActions)
                    ->delete();

                $deleted['audit_logs'] = $normalDeleted + $criticalDeleted;
            }
        });

        return [
            'execution_id' => Str::uuid()->toString(),
            'status' => 'completed',
            'deleted' => $deleted,
            'export_file' => $exportFile,
            'executed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 导出数据
     */
    protected function exportData(string $type, Collection $items): string
    {
        $exportDir = storage_path('app/maintenance_exports');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $filename = "export_{$type}_{$timestamp}.json";
        $filepath = "{$exportDir}/{$filename}";

        $exportData = [
            'type' => $type,
            'exported_at' => Carbon::now()->toIso8601String(),
            'items' => $items->toArray(),
        ];

        file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $filename;
    }

    /**
     * 删除或修复单个项目
     */
    protected function deleteItem(array $item, string $scanType, array &$deleted): void
    {
        switch ($item['type']) {
            case 'invoice':
                $invoice = Invoice::find($item['id']);
                if ($invoice) {
                    // 级联删除关联数据，并恢复还款的 allocated_amount
                    $deleted['invoice_items'] += InvoiceItem::where('invoice_id', $invoice->id)->delete();

                    // 删除分配并恢复还款金额
                    $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();
                    foreach ($allocations as $alloc) {
                        $payment = Payment::find($alloc->payment_id);
                        if ($payment) {
                            $payment->allocated_amount = max(0, $payment->allocated_amount - $alloc->amount);
                            $payment->save();
                        }
                        $alloc->delete();
                        $deleted['payment_allocations']++;
                    }

                    $deleted['payment_discounts'] += PaymentDiscount::where('invoice_id', $invoice->id)->delete();

                    // 删除附件并清理存储文件
                    $this->deleteAttachments(Invoice::class, $invoice->id, $deleted);

                    $invoice->delete();
                    $deleted['invoices']++;
                }
                break;

            case 'payment':
                $payment = Payment::find($item['id']);
                if ($payment) {
                    // 删除分配并恢复账单金额
                    $allocations = PaymentAllocation::where('payment_id', $payment->id)->get();
                    foreach ($allocations as $alloc) {
                        $invoice = Invoice::find($alloc->invoice_id);
                        if ($invoice) {
                            $invoice->paid_amount = max(0, $invoice->paid_amount - $alloc->amount);
                            // 重新计算状态
                            $invoice->status = $this->calculateExpectedStatus($invoice);
                            $invoice->save();
                        }
                        $alloc->delete();
                        $deleted['payment_allocations']++;
                    }

                    $deleted['payment_discounts'] += PaymentDiscount::where('payment_id', $payment->id)->delete();

                    // 删除附件并清理存储文件
                    $this->deleteAttachments(Payment::class, $payment->id, $deleted);

                    $payment->delete();
                    $deleted['payments']++;
                }
                break;

            case 'invoice_item':
                InvoiceItem::where('id', $item['id'])->delete();
                $deleted['invoice_items']++;
                break;

            case 'payment_allocation':
                $alloc = PaymentAllocation::find($item['id']);
                if ($alloc) {
                    // 恢复还款的 allocated_amount
                    $payment = Payment::find($alloc->payment_id);
                    if ($payment) {
                        $payment->allocated_amount = max(0, $payment->allocated_amount - $alloc->amount);
                        $payment->save();
                    }
                    // 恢复账单的 paid_amount
                    $invoice = Invoice::find($alloc->invoice_id);
                    if ($invoice) {
                        $invoice->paid_amount = max(0, $invoice->paid_amount - $alloc->amount);
                        $invoice->status = $this->calculateExpectedStatus($invoice);
                        $invoice->save();
                    }
                    $alloc->delete();
                    $deleted['payment_allocations']++;
                }
                break;

            case 'attachment':
                $attachment = Attachment::find($item['id']);
                if ($attachment) {
                    // 删除存储文件
                    $this->deleteStorageFile($attachment);
                    $attachment->delete();
                    $deleted['attachments']++;
                }
                break;

                // 完整性问题修复（而非删除）
            case 'invoice_amount_mismatch':
                $invoice = Invoice::find($item['id']);
                if ($invoice && isset($item['expected_value'])) {
                    $invoice->amount = $item['expected_value'];
                    $invoice->save();
                    // 使用 invoices 计数器标记已修复（更好的方式是添加 'fixed' 计数器）
                    $deleted['invoices']++;
                }
                break;

            case 'invoice_status_mismatch':
                $invoice = Invoice::find($item['id']);
                if ($invoice && isset($item['expected_value'])) {
                    $invoice->status = $item['expected_value'];
                    $invoice->save();
                    $deleted['invoices']++;
                }
                break;

            case 'payment_allocation_mismatch':
                // 重新计算并修复分配金额
                if (isset($item['entity_type'])) {
                    if ($item['entity_type'] === 'payment') {
                        $payment = Payment::find($item['id']);
                        if ($payment && isset($item['expected_value'])) {
                            $payment->allocated_amount = $item['expected_value'];
                            $payment->save();
                            $deleted['payments']++;
                        }
                    } elseif ($item['entity_type'] === 'invoice') {
                        $invoice = Invoice::find($item['id']);
                        if ($invoice && isset($item['expected_value'])) {
                            $invoice->paid_amount = $item['expected_value'];
                            $invoice->status = $this->calculateExpectedStatus($invoice);
                            $invoice->save();
                            $deleted['invoices']++;
                        }
                    }
                }
                break;

            case 'audit_log':
                AuditLog::where('id', $item['id'])->delete();
                // 使用通用计数器记录，或忽略（审计日志不需要精确计数）
                break;

            case 'share_token':
                InvoiceShareToken::where('id', $item['id'])->delete();
                $deleted['share_tokens'] = ($deleted['share_tokens'] ?? 0) + 1;
                break;
        }
    }

    /**
     * 删除附件并清理存储文件
     */
    protected function deleteAttachments(string $attachableType, int $attachableId, array &$deleted): void
    {
        $attachments = Attachment::where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->get();

        foreach ($attachments as $attachment) {
            $this->deleteStorageFile($attachment);
            $attachment->delete();
            $deleted['attachments']++;
        }
    }

    /**
     * 删除存储文件
     */
    protected function deleteStorageFile(Attachment $attachment): void
    {
        try {
            $disk = $attachment->disk ?? 'local';
            $path = $attachment->path ?? $attachment->file_path;

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Exception $e) {
            // 文件删除失败不影响数据库记录删除
            \Log::warning('Failed to delete attachment file: '.$e->getMessage());
        }
    }

    /**
     * 计算预期的账单状态（考虑优惠减免）
     */
    protected function calculateExpectedStatus(Invoice $invoice): string
    {
        // 获取该账单的优惠减免总额
        $discountAmount = PaymentDiscount::where('invoice_id', $invoice->id)->sum('discount_amount');

        // 实际已结算金额 = 已付金额 + 优惠减免
        $settledAmount = $invoice->paid_amount + $discountAmount;

        if ($settledAmount >= $invoice->amount && $invoice->amount > 0) {
            return 'paid';
        }
        if ($settledAmount > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    /**
     * 扫描过期的分享Token
     */
    public function scanExpiredTokens(array $options): array
    {
        $days = $options['days'] ?? 7; // 默认清理过期超过7天的Token
        $page = $options['page'] ?? 1;
        $perPage = $options['per_page'] ?? 50;

        $cutoffDate = Carbon::now()->subDays($days);
        $scanId = Str::uuid()->toString();

        // 查询过期的Token（expires_at 早于截止日期）
        $query = InvoiceShareToken::where('expires_at', '<', $cutoffDate);

        $totalCount = $query->count();

        // 获取分页数据
        $tokens = $query
            ->with(['customer:id,name', 'store:id,name', 'createdBy:id,name'])
            ->select(['id', 'token', 'customer_id', 'store_id', 'created_by', 'expires_at', 'created_at'])
            ->orderBy('expires_at', 'asc')
            ->forPage($page, $perPage)
            ->get();

        $items = $tokens->map(function ($token) {
            return [
                'id' => $token->id,
                'type' => 'share_token',
                'identifier' => substr($token->token, 0, 8).'...',
                'customer_name' => $token->customer?->name ?? '未知',
                'store_name' => $token->store?->name ?? '未知',
                'created_by' => $token->createdBy?->name ?? '系统',
                'expires_at' => $token->expires_at->format('Y-m-d H:i'),
                'created_at' => $token->created_at->format('Y-m-d'),
                'can_delete' => true,
            ];
        })->toArray();

        // 缓存扫描结果
        cache()->put("maintenance_scan:{$scanId}", [
            'type' => 'token_cleanup',
            'options' => $options,
            'cutoff_date' => $cutoffDate->toDateString(),
            'items' => $items,
        ], now()->addHours(1));

        return [
            'scan_id' => $scanId,
            'type' => 'token_cleanup',
            'scanned_at' => Carbon::now()->toIso8601String(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'summary' => [
                'share_tokens' => $totalCount,
            ],
            'items' => $items,
            'total_items' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
