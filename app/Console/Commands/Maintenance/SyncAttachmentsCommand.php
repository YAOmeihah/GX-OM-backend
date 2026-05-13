<?php

namespace App\Console\Commands\Maintenance;

use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SyncAttachmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:sync-attachments 
                            {--check : 仅检查，不修复}
                            {--fix-orphan-files : 删除S3中的孤立文件}
                            {--fix-orphan-records : 删除数据库中的孤立记录}
                            {--older-than=7 : 仅处理N天前的文件}
                            {--dry-run : 模拟运行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步数据库附件记录与S3存储，检测并清理孤立数据';

    /**
     * S3 磁盘名称
     */
    protected string $diskName = 's3-compat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checkOnly = $this->option('check');
        $fixOrphanFiles = $this->option('fix-orphan-files');
        $fixOrphanRecords = $this->option('fix-orphan-records');
        $olderThanDays = (int) $this->option('older-than');
        $isDryRun = $this->option('dry-run');

        $this->info("=== S3 附件同步工具 ===");
        if ($isDryRun) {
            $this->warn("[模拟运行模式]");
        }
        $this->newLine();

        // 检测数据库有记录但S3无文件
        $this->info('1. 检测数据库记录但S3无文件...');
        $missingFiles = $this->findMissingS3Files();
        $this->displayMissingFiles($missingFiles);

        // 检测S3有文件但数据库无记录
        $this->info('2. 检测S3文件但数据库无记录...');
        $orphanFiles = $this->findOrphanS3Files($olderThanDays);
        $this->displayOrphanFiles($orphanFiles);

        // 修复操作
        if (!$checkOnly && !$isDryRun) {
            if ($fixOrphanRecords && count($missingFiles) > 0) {
                $this->fixOrphanRecords($missingFiles);
            }

            if ($fixOrphanFiles && count($orphanFiles) > 0) {
                $this->fixOrphanFiles($orphanFiles);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 查找数据库有记录但S3无文件的附件
     */
    protected function findMissingS3Files(): array
    {
        $missing = [];
        $disk = Storage::disk($this->diskName);

        Attachment::chunk(100, function ($attachments) use (&$missing, $disk) {
            foreach ($attachments as $attachment) {
                if (empty($attachment->file_path)) {
                    continue;
                }

                try {
                    if (!$disk->exists($attachment->file_path)) {
                        $missing[] = [
                            'id' => $attachment->id,
                            'file_path' => $attachment->file_path,
                            'original_filename' => $attachment->original_filename,
                            'attachable_type' => $attachment->attachable_type,
                            'attachable_id' => $attachment->attachable_id,
                            'created_at' => $attachment->created_at->toDateTimeString(),
                        ];
                    }
                } catch (\Exception $e) {
                    $this->warn("  检查文件失败: {$attachment->file_path} - {$e->getMessage()}");
                }
            }
        });

        return $missing;
    }

    /**
     * 查找S3有文件但数据库无记录的文件
     */
    protected function findOrphanS3Files(int $olderThanDays): array
    {
        $orphans = [];
        $disk = Storage::disk($this->diskName);
        $cutoffDate = Carbon::now()->subDays($olderThanDays);

        try {
            // 获取所有文件路径
            $dbPaths = Attachment::pluck('file_path')->filter()->toArray();

            // 列出S3中的文件
            $s3Files = $disk->allFiles();

            foreach ($s3Files as $s3Path) {
                if (!in_array($s3Path, $dbPaths)) {
                    try {
                        $lastModified = Carbon::createFromTimestamp($disk->lastModified($s3Path));

                        // 只处理超过指定天数的文件
                        if ($lastModified->lt($cutoffDate)) {
                            $orphans[] = [
                                'file_path' => $s3Path,
                                'size' => $disk->size($s3Path),
                                'last_modified' => $lastModified->toDateTimeString(),
                            ];
                        }
                    } catch (\Exception $e) {
                        // 无法获取文件信息，仍然标记为孤立
                        $orphans[] = [
                            'file_path' => $s3Path,
                            'size' => 0,
                            'last_modified' => 'unknown',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("无法列出S3文件: {$e->getMessage()}");
        }

        return $orphans;
    }

    /**
     * 显示缺失的S3文件
     */
    protected function displayMissingFiles(array $missing): void
    {
        if (empty($missing)) {
            $this->info("  ✓ 所有数据库记录都有对应的S3文件");
        } else {
            $this->warn("  发现 " . count($missing) . " 条记录缺少S3文件");

            if (count($missing) <= 10) {
                foreach ($missing as $item) {
                    $this->line("    - ID:{$item['id']} - {$item['original_filename']}");
                }
            } else {
                $this->line("    (显示前10条)");
                foreach (array_slice($missing, 0, 10) as $item) {
                    $this->line("    - ID:{$item['id']} - {$item['original_filename']}");
                }
            }
        }
        $this->newLine();
    }

    /**
     * 显示孤立的S3文件
     */
    protected function displayOrphanFiles(array $orphans): void
    {
        if (empty($orphans)) {
            $this->info("  ✓ S3中没有孤立文件");
        } else {
            $totalSize = array_sum(array_column($orphans, 'size'));
            $sizeHuman = $this->formatBytes($totalSize);

            $this->warn("  发现 " . count($orphans) . " 个孤立文件 (共 {$sizeHuman})");

            if (count($orphans) <= 10) {
                foreach ($orphans as $item) {
                    $this->line("    - {$item['file_path']} ({$this->formatBytes($item['size'])})");
                }
            } else {
                $this->line("    (显示前10个)");
                foreach (array_slice($orphans, 0, 10) as $item) {
                    $this->line("    - {$item['file_path']} ({$this->formatBytes($item['size'])})");
                }
            }
        }
        $this->newLine();
    }

    /**
     * 修复孤立的数据库记录
     */
    protected function fixOrphanRecords(array $missing): void
    {
        if (!$this->confirm('确认删除缺少S3文件的数据库记录?')) {
            return;
        }

        $this->info('正在删除孤立的数据库记录...');
        $ids = array_column($missing, 'id');
        $deleted = Attachment::whereIn('id', $ids)->delete();
        $this->info("  已删除 {$deleted} 条记录");
    }

    /**
     * 修复孤立的S3文件
     */
    protected function fixOrphanFiles(array $orphans): void
    {
        if (!$this->confirm('确认删除S3中的孤立文件?')) {
            return;
        }

        $this->info('正在删除S3孤立文件...');
        $disk = Storage::disk($this->diskName);
        $deleted = 0;

        foreach ($orphans as $item) {
            try {
                $disk->delete($item['file_path']);
                $deleted++;
            } catch (\Exception $e) {
                $this->warn("  删除失败: {$item['file_path']} - {$e->getMessage()}");
            }
        }

        $this->info("  已删除 {$deleted} 个文件");
    }

    /**
     * 格式化字节数
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
