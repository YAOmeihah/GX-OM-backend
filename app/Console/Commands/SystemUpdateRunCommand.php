<?php

namespace App\Console\Commands;

use App\Models\SystemUpdateRun;
use App\Services\SystemUpdate\SystemUpdateService;
use Illuminate\Console\Command;
use Throwable;

class SystemUpdateRunCommand extends Command
{
    protected $signature = 'system-update:run {run : System update run id}';

    protected $description = 'Install a queued system update package';

    public function handle(SystemUpdateService $systemUpdateService): int
    {
        $run = SystemUpdateRun::query()->find((int) $this->argument('run'));

        if (! $run) {
            $this->error('System update run was not found.');

            return self::FAILURE;
        }

        if (! in_array($run->status, ['pending', 'uploaded', 'queued', 'failed', 'running'], true)) {
            $this->error("System update run is not installable from status [{$run->status}].");

            return self::FAILURE;
        }

        if (! $run->package_path || ! is_file($run->package_path)) {
            $run->update([
                'status' => 'failed',
                'step' => 'uploaded',
                'error_message' => 'Uploaded release package is missing.',
                'finished_at' => now(),
            ]);
            $this->error('Uploaded release package is missing.');

            return self::FAILURE;
        }

        try {
            $systemUpdateService->executeRun($run, true);

            $this->info('System update install completed.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $run->update([
                'status' => 'failed',
                'step' => 'rolled_back',
                'log_lines' => array_merge($run->log_lines ?? [], ['Uploaded release package install failed; rollback attempted.']),
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
            ]);

            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
