<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanupAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup 
                            {--days= : 保留天数，默认使用配置文件中的值}
                            {--keep-critical=365 : 关键操作 (delete/discount) 保留天数}
                            {--archive : 删除前归档到JSON文件}
                            {--dry-run : 模拟运行，不实际删除}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理过期的审计日志记录 (支持分级保留和归档)';

    /**
     * 关键操作类型 (保留更长时间)
     */
    protected array $criticalActions = [
        'delete',
        'discount',
        'revoke_allocation',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = $this->option('days') ?? config('audit.retention_days', 90);
        $criticalRetentionDays = (int) $this->option('keep-critical');
        $shouldArchive = $this->option('archive');
        $isDryRun = $this->option('dry-run');

        if ($retentionDays <= 0) {
            $this->info('审计日志保留天数设置为0，不执行清理。');

            return Command::SUCCESS;
        }

        $normalCutoffDate = Carbon::now()->subDays($retentionDays);
        $criticalCutoffDate = Carbon::now()->subDays($criticalRetentionDays);

        $this->info('=== 审计日志清理工具 ===');
        $this->info("普通操作保留: {$retentionDays} 天 (截止: {$normalCutoffDate->toDateString()})");
        $this->info("关键操作保留: {$criticalRetentionDays} 天 (截止: {$criticalCutoffDate->toDateString()})");
        $this->info('关键操作类型: '.implode(', ', $this->criticalActions));
        if ($isDryRun) {
            $this->warn('[模拟运行模式]');
        }
        $this->newLine();

        // 统计待清理数量
        $normalCount = AuditLog::where('created_at', '<', $normalCutoffDate)
            ->whereNotIn('action', $this->criticalActions)
            ->count();

        $criticalCount = AuditLog::where('created_at', '<', $criticalCutoffDate)
            ->whereIn('action', $this->criticalActions)
            ->count();

        $totalCount = $normalCount + $criticalCount;

        if ($totalCount === 0) {
            $this->info('没有需要清理的审计日志。');

            return Command::SUCCESS;
        }

        $this->info('待清理统计:');
        $this->table(
            ['类型', '数量'],
            [
                ['普通操作', $normalCount],
                ['关键操作', $criticalCount],
                ['合计', $totalCount],
            ]
        );
        $this->newLine();

        if ($isDryRun) {
            $this->info("[模拟运行] 将删除 {$totalCount} 条审计日志记录。");

            return Command::SUCCESS;
        }

        // 归档
        if ($shouldArchive) {
            $this->archiveLogs($normalCutoffDate, $criticalCutoffDate);
        }

        // 执行清理
        $this->info('正在清理审计日志...');

        $deleted = 0;
        $batchSize = 1000;

        // 清理普通操作
        $this->info('清理普通操作日志...');
        $bar = $this->output->createProgressBar($normalCount);
        $bar->start();

        while (true) {
            $batch = AuditLog::where('created_at', '<', $normalCutoffDate)
                ->whereNotIn('action', $this->criticalActions)
                ->limit($batchSize)
                ->delete();

            if ($batch === 0) {
                break;
            }

            $deleted += $batch;
            $bar->advance($batch);
        }

        $bar->finish();
        $this->newLine();

        // 清理关键操作
        $this->info('清理关键操作日志...');
        $bar2 = $this->output->createProgressBar($criticalCount);
        $bar2->start();

        while (true) {
            $batch = AuditLog::where('created_at', '<', $criticalCutoffDate)
                ->whereIn('action', $this->criticalActions)
                ->limit($batchSize)
                ->delete();

            if ($batch === 0) {
                break;
            }

            $deleted += $batch;
            $bar2->advance($batch);
        }

        $bar2->finish();
        $this->newLine(2);

        $this->info("成功删除 {$deleted} 条审计日志记录。");

        return Command::SUCCESS;
    }

    /**
     * 归档日志到JSON文件
     */
    protected function archiveLogs(Carbon $normalCutoffDate, Carbon $criticalCutoffDate): void
    {
        $this->info('正在归档日志...');

        $exportDir = storage_path('app/maintenance_exports/audit_archives');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $exportFile = "{$exportDir}/audit_archive_{$timestamp}.json";

        // 获取待归档的日志
        $normalLogs = AuditLog::where('created_at', '<', $normalCutoffDate)
            ->whereNotIn('action', $this->criticalActions)
            ->get();

        $criticalLogs = AuditLog::where('created_at', '<', $criticalCutoffDate)
            ->whereIn('action', $this->criticalActions)
            ->get();

        $archiveData = [
            'archived_at' => Carbon::now()->toIso8601String(),
            'normal_cutoff_date' => $normalCutoffDate->toDateString(),
            'critical_cutoff_date' => $criticalCutoffDate->toDateString(),
            'normal_logs' => $normalLogs->toArray(),
            'critical_logs' => $criticalLogs->toArray(),
        ];

        file_put_contents($exportFile, json_encode($archiveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("日志已归档到: {$exportFile}");
        $this->newLine();
    }
}
