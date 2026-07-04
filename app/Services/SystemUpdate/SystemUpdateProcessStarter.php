<?php

namespace App\Services\SystemUpdate;

use App\Models\SystemUpdateRun;
use RuntimeException;

class SystemUpdateProcessStarter
{
    public function start(SystemUpdateRun $run): void
    {
        $binary = $this->phpBinary();
        $artisan = base_path('artisan');
        $logPath = storage_path("logs/system-update-{$run->id}.log");

        $this->ensureDirectory(dirname($logPath));

        $baseCommand = escapeshellarg($binary).' '.escapeshellarg($artisan).' system-update:run '.escapeshellarg((string) $run->id);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B "" '.$baseCommand.' > '.escapeshellarg($logPath).' 2>&1';
        } else {
            $command = $baseCommand.' > '.escapeshellarg($logPath).' 2>&1 &';
        }

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Failed to start system update background process: '.implode("\n", $output));
        }
    }

    private function phpBinary(): string
    {
        $configuredBinary = trim((string) config('system_update.php_binary', ''));

        return $configuredBinary !== '' ? $configuredBinary : PHP_BINARY;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}
