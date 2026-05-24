<?php

namespace App\Services\SystemUpdate;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemUpdateEnvironmentPreflight
{
    private const CHECKS = [
        'workspace_writable',
        'downloads_dir_creatable',
        'staging_dir_creatable',
        'backups_dir_creatable',
        'phar_available',
        'exec_enabled',
        'artisan_readable',
        'symlink_supported',
        'database_reachable',
    ];

    public function __construct(
        private readonly ?Filesystem $files = null,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $checks = [
            $this->workspaceWritable(),
            $this->downloadsDirCreatable(),
            $this->stagingDirCreatable(),
            $this->backupsDirCreatable(),
            $this->pharAvailable(),
            $this->execEnabled(),
            $this->artisanReadable(),
            $this->symlinkSupported(),
            $this->databaseReachable(),
        ];

        return [
            'passed' => ! in_array(false, array_map(static fn (array $check): bool => (bool) $check['passed'], $checks), true),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureReady(): array
    {
        $report = $this->check();

        if (! $report['passed']) {
            throw new SystemUpdateEnvironmentNotReadyException($report);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceWritable(): array
    {
        $root = $this->deploymentRoot();
        $path = $this->join($root, 'storage/app/system_updates');

        return $this->ensureWritableDirectory(
            id: 'workspace_writable',
            label: '系统更新工作区可写',
            path: $path,
            detailWhenPass: "工作区可写：{$path}",
            remediation: '确认部署目录可写，并检查当前 PHP/FPM 用户是否有写入权限。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadsDirCreatable(): array
    {
        $root = $this->deploymentRoot();
        $path = $this->join($root, 'storage/app/system_updates/downloads');

        return $this->ensureWritableDirectory(
            id: 'downloads_dir_creatable',
            label: '下载目录可创建',
            path: $path,
            detailWhenPass: "下载目录可创建：{$path}",
            remediation: '确认 deployment root 的 storage/app/system_updates 目录可写。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stagingDirCreatable(): array
    {
        $root = $this->deploymentRoot();
        $path = $this->join($root, 'storage/app/system_updates/staging');

        return $this->ensureWritableDirectory(
            id: 'staging_dir_creatable',
            label: '解压暂存目录可创建',
            path: $path,
            detailWhenPass: "解压暂存目录可创建：{$path}",
            remediation: '确认 deployment root 的 storage/app/system_updates 目录可写。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function backupsDirCreatable(): array
    {
        $root = $this->deploymentRoot();
        $path = $this->join($root, 'storage/app/system_updates/backups');

        return $this->ensureWritableDirectory(
            id: 'backups_dir_creatable',
            label: '备份目录可创建',
            path: $path,
            detailWhenPass: "备份目录可创建：{$path}",
            remediation: '确认 deployment root 的 storage/app/system_updates 目录可写。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pharAvailable(): array
    {
        $passed = class_exists(\PharData::class);

        return $this->result(
            id: 'phar_available',
            label: 'PharData 可用',
            passed: $passed,
            detailWhenPass: 'PharData 扩展可用，可用于解压 .tar.gz。',
            detailWhenFail: '当前 PHP 环境缺少 PharData，无法解压发布包。',
            remediation: '安装或启用 phar 扩展。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function execEnabled(): array
    {
        $passed = function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

        return $this->result(
            id: 'exec_enabled',
            label: 'exec() 可用',
            passed: $passed,
            detailWhenPass: 'exec() 可用，可执行 artisan 命令。',
            detailWhenFail: '当前 PHP 禁用了 exec()，无法执行 artisan up/down/migrate 等命令。',
            remediation: '放开 PHP 的 exec() 禁用列表，或改用允许执行命令的运行环境。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artisanReadable(): array
    {
        $path = $this->join($this->deploymentRoot(), 'artisan');
        $passed = is_file($path) && is_readable($path);

        return $this->result(
            id: 'artisan_readable',
            label: 'artisan 可读',
            passed: $passed,
            detailWhenPass: "artisan 可读：{$path}",
            detailWhenFail: "当前部署目录未找到可读的 artisan 文件：{$path}",
            remediation: '确认更新包已正确部署到 Laravel 项目根目录。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function symlinkSupported(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->result(
                id: 'symlink_supported',
                label: '符号链接可用',
                passed: function_exists('symlink'),
                detailWhenPass: '当前环境已启用 symlink 函数。',
                detailWhenFail: '当前环境未启用 symlink 函数，storage:link 可能失败。',
                remediation: '确认系统和 PHP 进程允许创建符号链接。',
            );
        }

        $root = $this->deploymentRoot();
        $target = $this->join($root, 'storage/app/system_updates/.preflight-link-target');
        $link = $this->join($root, 'storage/app/system_updates/.preflight-link');

        try {
            $this->ensureWritableDirectory(dirname($target));
            file_put_contents($target, 'ok');

            if (is_link($link) || file_exists($link)) {
                @unlink($link);
            }

            $passed = @symlink($target, $link);

            if ($passed && is_link($link)) {
                @unlink($link);
            }
        } catch (Throwable) {
            $passed = false;
        } finally {
            @unlink($target);
            if (is_link($link) || file_exists($link)) {
                @unlink($link);
            }
        }

        return $this->result(
            id: 'symlink_supported',
            label: '符号链接可用',
            passed: (bool) $passed,
            detailWhenPass: '当前环境支持创建符号链接，可执行 storage:link。',
            detailWhenFail: '当前环境无法创建符号链接，storage:link 可能失败。',
            remediation: '确认系统和 PHP 进程允许创建符号链接。',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseReachable(): array
    {
        try {
            $connection = $this->connection ?? DB::connection();
            $connection->getPdo();

            return $this->result(
                id: 'database_reachable',
                label: '数据库可连接',
                passed: true,
                detailWhenPass: '数据库连接正常，可执行迁移。',
                detailWhenFail: '数据库不可用，无法执行 migrate --force。',
                remediation: '检查数据库连接配置、网络连通性和数据库服务状态。',
            );
        } catch (Throwable $throwable) {
            return $this->result(
                id: 'database_reachable',
                label: '数据库可连接',
                passed: false,
                detailWhenPass: '数据库连接正常，可执行迁移。',
                detailWhenFail: '数据库不可用，无法执行 migrate --force.',
                remediation: '检查数据库连接配置、网络连通性和数据库服务状态。',
                detail: $throwable->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureWritableDirectory(string $id, string $label, string $path, string $detailWhenPass, string $remediation): array
    {
        try {
            if (! is_dir($path)) {
                mkdir($path, 0777, true);
            }

            $probeFile = $this->join($path, '.preflight-write-probe');
            file_put_contents($probeFile, 'ok');
            @unlink($probeFile);

            return $this->result($id, $label, true, $detailWhenPass, '目录不可写或无法创建。', $remediation);
        } catch (Throwable $throwable) {
            return $this->result($id, $label, false, $detailWhenPass, $throwable->getMessage(), $remediation);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $id,
        string $label,
        bool $passed,
        string $detailWhenPass,
        string $detailWhenFail,
        string $remediation,
        ?string $detail = null,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'passed' => $passed,
            'detail' => $detail ?? ($passed ? $detailWhenPass : $detailWhenFail),
            'remediation' => $remediation,
        ];
    }

    private function deploymentRoot(): string
    {
        return rtrim((string) config('system_update.deployment_root', base_path()), DIRECTORY_SEPARATOR.'/\\');
    }

    private function join(string $root, string $path): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
