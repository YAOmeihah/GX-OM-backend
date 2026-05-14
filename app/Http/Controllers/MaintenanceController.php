<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\MaintenanceScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    protected MaintenanceScanService $scanService;

    protected AuditLogService $auditLogService;

    public function __construct(MaintenanceScanService $scanService, AuditLogService $auditLogService)
    {
        $this->scanService = $scanService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * 扫描待处理数据
     *
     * @group 数据维护
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['history_cleanup', 'orphan_check', 'integrity_check', 'audit_cleanup', 'token_cleanup'])],
            'options' => 'sometimes|array',
            'options.months' => 'sometimes|integer|min:1|max:120',
            'options.targets' => 'sometimes|array',
            'options.targets.*' => Rule::in(['invoices', 'payments']),
            'options.exclude_stores' => 'sometimes|array',
            'options.exclude_stores.*' => 'integer',
            'options.types' => 'sometimes|array',
            'options.normal_days' => 'sometimes|integer|min:1|max:3650',
            'options.critical_days' => 'sometimes|integer|min:1|max:3650',
            'options.page' => 'sometimes|integer|min:1',
            'options.per_page' => 'sometimes|integer|min:10|max:100',
        ]);

        $type = $validated['type'];
        $options = $validated['options'] ?? [];

        try {
            $result = match ($type) {
                'history_cleanup' => $this->scanService->scanHistoryCleanup($options),
                'orphan_check' => $this->scanService->scanOrphanData($options),
                'integrity_check' => $this->scanService->scanIntegrityIssues($options),
                'audit_cleanup' => $this->scanService->scanAuditLogs($options),
                'token_cleanup' => $this->scanService->scanExpiredTokens($options),
            };

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '扫描失败: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * 执行清理操作
     *
     * @group 数据维护
     */
    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scan_id' => 'required|uuid',
            'selected_keys' => ['sometimes', 'array'],
            'selected_keys.*' => ['string', 'regex:/^[a-z_]+:\d+$/'],
            'selected_ids' => ['sometimes', 'array'],
            'selected_ids.*' => ['integer'],
            'export_before_delete' => 'sometimes|boolean',
        ]);

        try {
            $result = $this->scanService->executeCleanup(
                $validated['scan_id'],
                $validated['selected_keys'] ?? [],
                $validated['export_before_delete'] ?? false,
                $validated['selected_ids'] ?? []
            );

            // 记录审计日志（使用统一服务）
            $this->auditLogService->log(
                'maintenance_execute',
                null,
                null,
                [
                    'scan_id' => $validated['scan_id'],
                    'selected_count' => count($validated['selected_keys'] ?? $validated['selected_ids'] ?? []),
                    'exported' => $validated['export_before_delete'] ?? false,
                    'deleted' => $result['deleted'],
                ],
                '执行数据维护清理操作'
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '执行失败: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取维护执行历史
     *
     * @group 数据维护
     */
    public function history(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);

        $history = \App\Models\AuditLog::where('action', 'maintenance_execute')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'executed_by' => $log->user_name,
                    'executed_at' => $log->created_at->toIso8601String(),
                    'deleted' => $log->new_values,
                    'metadata' => $log->metadata,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * 导出扫描结果
     *
     * @group 数据维护
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scan_id' => 'required|uuid',
        ]);

        $scanData = cache()->get("maintenance_scan:{$validated['scan_id']}");

        if (! $scanData) {
            return response()->json([
                'success' => false,
                'message' => '扫描结果已过期，请重新扫描',
            ], 400);
        }

        $exportDir = storage_path('app/maintenance_exports');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = now()->format('Ymd_His');
        $filename = "export_{$scanData['type']}_{$timestamp}.json";
        $filepath = "{$exportDir}/{$filename}";

        file_put_contents($filepath, json_encode($scanData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'download_url' => url("/api/maintenance/export/{$filename}"),
                'size' => filesize($filepath),
            ],
        ]);
    }

    /**
     * 下载导出文件
     *
     * @group 数据维护
     */
    public function downloadExport(string $filename)
    {
        $filename = basename($filename);
        if (! preg_match('/^export_[a-z_]+_\d{8}_\d{6}\.json$/', $filename)) {
            abort(404, '文件不存在');
        }

        $exportDir = storage_path('app/maintenance_exports');
        $filepath = $exportDir.DIRECTORY_SEPARATOR.$filename;
        $realExportDir = realpath($exportDir);
        $realFile = realpath($filepath);

        if (! $realExportDir || ! $realFile || ! str_starts_with($realFile, $realExportDir.DIRECTORY_SEPARATOR)) {
            abort(404, '文件不存在');
        }

        return response()->download($realFile, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * 获取可用的维护类型
     *
     * @group 数据维护
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'type' => 'history_cleanup',
                    'name' => '历史数据清理',
                    'description' => '清理指定时间之前的已结清账单和还款记录',
                    'icon' => 'history',
                    'danger_level' => 'high',
                ],
                [
                    'type' => 'orphan_check',
                    'name' => '孤立数据清理',
                    'description' => '检测并清理失去父级引用的数据记录',
                    'icon' => 'unlink',
                    'danger_level' => 'medium',
                ],
                [
                    'type' => 'integrity_check',
                    'name' => '数据完整性检查',
                    'description' => '检测并修复金额、状态等数据不一致问题',
                    'icon' => 'check-circle',
                    'danger_level' => 'low',
                ],
                [
                    'type' => 'audit_cleanup',
                    'name' => '审计日志清理',
                    'description' => '清理过期的审计日志记录（普通操作90天/关键操作365天）',
                    'icon' => 'file-text',
                    'danger_level' => 'medium',
                ],
                [
                    'type' => 'token_cleanup',
                    'name' => '分享Token清理',
                    'description' => '清理过期的账单分享Token记录',
                    'icon' => 'link',
                    'danger_level' => 'low',
                ],
            ],
        ]);
    }
}
