<?php

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunMaintenanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:run 
                            {--profile=daily : 预设配置: daily, weekly, monthly}
                            {--dry-run : 模拟运行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '运行综合维护任务 (统一调度入口)';

    /**
     * 预设配置
     */
    protected array $profiles = [
        'daily' => [
            'description' => '每日维护：审计日志清理 + 数据完整性检查',
            'commands' => [
                ['audit:cleanup', ['--days' => 90]],
                ['maintenance:integrity-check', ['--type' => 'all']],
            ],
        ],
        'weekly' => [
            'description' => '每周维护：每日任务 + 孤立数据清理 + 附件同步检查',
            'commands' => [
                ['audit:cleanup', ['--days' => 90]],
                ['maintenance:integrity-check', ['--type' => 'all']],
                ['maintenance:orphan-check', ['--type' => 'all', '--fix' => true]],
                ['maintenance:sync-attachments', ['--check' => true]],
            ],
        ],
        'monthly' => [
            'description' => '每月维护：每周任务 + 历史数据归档',
            'commands' => [
                ['audit:cleanup', ['--days' => 90]],
                ['maintenance:integrity-check', ['--type' => 'all', '--fix' => true]],
                ['maintenance:orphan-check', ['--type' => 'all', '--fix' => true]],
                ['maintenance:sync-attachments', ['--fix-orphan-files' => true, '--fix-orphan-records' => true]],
                ['maintenance:cleanup-history', ['--months' => 6, '--include' => 'invoices,payments', '--export' => true]],
            ],
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $profile = $this->option('profile');
        $isDryRun = $this->option('dry-run');

        if (!isset($this->profiles[$profile])) {
            $this->error("未知的配置文件: {$profile}");
            $this->info("可用的配置: " . implode(', ', array_keys($this->profiles)));
            return Command::FAILURE;
        }

        $config = $this->profiles[$profile];

        $this->info("========================================");
        $this->info("     GX-OM 综合维护任务调度器");
        $this->info("========================================");
        $this->newLine();
        $this->info("配置文件: {$profile}");
        $this->info("描述: {$config['description']}");
        $this->info("开始时间: " . Carbon::now()->toDateTimeString());
        if ($isDryRun) {
            $this->warn("[模拟运行模式]");
        }
        $this->newLine();

        $totalCommands = count($config['commands']);
        $completedCommands = 0;
        $failedCommands = [];

        foreach ($config['commands'] as $index => $commandConfig) {
            [$commandName, $options] = $commandConfig;

            // 添加 dry-run 选项
            if ($isDryRun) {
                $options['--dry-run'] = true;
            }

            $this->info("----------------------------------------");
            $this->info("[" . ($index + 1) . "/{$totalCommands}] 执行: {$commandName}");
            $this->info("----------------------------------------");

            try {
                $exitCode = $this->call($commandName, $options);

                if ($exitCode === Command::SUCCESS) {
                    $completedCommands++;
                    $this->info("✓ {$commandName} 完成");
                } else {
                    $failedCommands[] = $commandName;
                    $this->error("✗ {$commandName} 失败 (退出码: {$exitCode})");
                }
            } catch (\Exception $e) {
                $failedCommands[] = $commandName;
                $this->error("✗ {$commandName} 异常: {$e->getMessage()}");
            }

            $this->newLine();
        }

        // 汇总报告
        $this->info("========================================");
        $this->info("          维护任务执行报告");
        $this->info("========================================");
        $this->info("完成时间: " . Carbon::now()->toDateTimeString());
        $this->info("成功任务: {$completedCommands}/{$totalCommands}");

        if (!empty($failedCommands)) {
            $this->error("失败任务: " . implode(', ', $failedCommands));
            return Command::FAILURE;
        }

        $this->info("所有任务执行成功!");
        return Command::SUCCESS;
    }
}
