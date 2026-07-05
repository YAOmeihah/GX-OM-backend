<?php

namespace App\Console\Commands;

use App\Services\SystemUpdate\SystemUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SystemUpdateWorkerCommand extends Command
{
    protected $signature = 'system-update:worker {--once : Process at most one queued system update run}';

    protected $description = 'Process queued system update uploads from the CLI';

    public function handle(SystemUpdateService $systemUpdateService): int
    {
        $lock = Cache::lock('system-update:worker', (int) config('system_update.worker_lock_seconds', 3600));

        if (! $lock->get()) {
            $this->line('Another system update worker is already running.');

            return self::SUCCESS;
        }

        try {
            $staleCount = $systemUpdateService->markStaleRunningRunsFailed();

            if ($staleCount > 0) {
                $this->warn("Marked {$staleCount} stale system update run(s) as failed.");
            }

            $processed = 0;
            $failed = false;

            do {
                $run = $systemUpdateService->nextRunnableRun();

                if (! $run) {
                    if ($processed === 0) {
                        $this->info('No queued system update runs.');
                    }

                    break;
                }

                $this->info("Processing system update run {$run->id} ({$run->tag}).");
                $result = $systemUpdateService->executeRun($run);
                $processed++;

                if (($result['status'] ?? null) !== 'completed') {
                    $failed = true;
                    $this->error("System update run {$run->id} failed.");
                } else {
                    $this->info("System update run {$run->id} completed.");
                }
            } while (! $this->option('once'));

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);
            $this->error($throwable->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
