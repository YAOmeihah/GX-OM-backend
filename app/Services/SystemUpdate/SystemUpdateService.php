<?php

namespace App\Services\SystemUpdate;

use App\Models\SystemUpdateRun;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Throwable;

class SystemUpdateService
{
    public function __construct(
        private readonly GitHubReleaseClient $githubReleaseClient,
        private readonly InPlaceReleaseInstaller $installer,
        private readonly SystemUpdateEnvironmentPreflight $environmentPreflight,
        private readonly ReleasePackageVerifier $verifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checkForUpdate(): array
    {
        $current = $this->currentRelease();
        $latest = $this->githubReleaseClient->latestRelease();

        return [
            'current' => $current,
            'latest' => $latest,
            'has_update' => $this->hasUpdate($current['version'], $latest['version']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function currentRelease(): array
    {
        $path = base_path('release.json');

        if (! is_file($path)) {
            return $this->currentGitRelease();
        }

        $metadata = json_decode((string) file_get_contents($path), true);

        if (! is_array($metadata)) {
            return $this->currentGitRelease();
        }

        return [
            'found' => true,
            'source' => 'release.json',
            'version' => (string) ($metadata['version'] ?? 'unknown'),
            'tag' => $metadata['tag'] ?? null,
            'commit' => $metadata['commit'] ?? null,
            'build_time' => $metadata['build_time'] ?? null,
        ];
    }

    /**
     * @param  array{tag: string, sha256: string}  $payload
     * @return array<string, mixed>
     */
    public function createUploadedPackageRun(array $payload, ?UploadedFile $package): array
    {
        if (! $package instanceof UploadedFile) {
            throw new \UnexpectedValueException('Release package file is missing.');
        }

        $this->environmentPreflight->ensureReady();

        $tag = $payload['tag'];
        $providedSha256 = strtolower($payload['sha256']);
        $expectedName = "gx-om-backend-{$tag}.tar.gz";
        $originalName = $package->getClientOriginalName();

        $this->verifier->assertValidTag($tag);

        if ($originalName !== $expectedName) {
            throw new \UnexpectedValueException("Uploaded release package filename must be {$expectedName}.");
        }

        $uploadedPath = $package->getRealPath();

        if (! is_string($uploadedPath) || ! is_file($uploadedPath)) {
            throw new \UnexpectedValueException('Uploaded release package file is not readable.');
        }

        $actualSha256 = strtolower(hash_file('sha256', $uploadedPath));

        if ($actualSha256 !== $providedSha256) {
            throw new \UnexpectedValueException('Uploaded release package SHA256 mismatch.');
        }

        $run = SystemUpdateRun::create([
            'actor_user_id' => auth()->id(),
            'tag' => $tag,
            'version' => ltrim($tag, 'v'),
            'status' => 'uploaded',
            'step' => 'uploaded',
            'package_sha256' => $providedSha256,
            'metadata' => [
                'source' => 'upload',
                'package_name' => $expectedName,
                'uploaded_size' => $package->getSize(),
            ],
            'log_lines' => ['Uploaded release package.', 'Waiting for manual server script install.'],
        ]);

        $destination = $this->uploadedPackagePath($tag, $run->id, $expectedName);
        $this->ensureDirectory(dirname($destination));
        $package->move(dirname($destination), basename($destination));

        $run->update(['package_path' => $destination]);
        $run->refresh();

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'step' => $run->step,
            'package_path' => $run->package_path,
            'install_command' => $this->manualInstallCommand((string) $run->package_path, $providedSha256),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueRun(SystemUpdateRun $run): array
    {
        $run->refresh();

        if (! $this->isQueueableRun($run)) {
            throw new \UnexpectedValueException("System update run is not queueable from status [{$run->status}].");
        }

        if (! $run->package_path || ! is_file($run->package_path)) {
            $run->update([
                'status' => 'failed',
                'step' => 'uploaded',
                'error_message' => 'Uploaded release package is missing.',
                'finished_at' => now(),
            ]);

            throw new \UnexpectedValueException('Uploaded release package is missing.');
        }

        $run->update([
            'status' => 'queued',
            'step' => 'queued',
            'error_message' => null,
            'finished_at' => null,
            'log_lines' => array_merge($run->log_lines ?? [], ['Queued for CLI system update worker.']),
        ]);

        return [
            'run_id' => $run->id,
            'status' => 'queued',
            'step' => 'queued',
        ];
    }

    public function nextRunnableRun(): ?SystemUpdateRun
    {
        return SystemUpdateRun::query()
            ->whereIn('status', ['queued', 'uploaded', 'pending'])
            ->orderByRaw("case status when 'queued' then 0 when 'uploaded' then 1 else 2 end")
            ->oldest('id')
            ->first();
    }

    public function markStaleRunningRunsFailed(): int
    {
        $staleMinutes = (int) config('system_update.stale_run_minutes', 10);

        if ($staleMinutes < 1) {
            return 0;
        }

        $threshold = now()->subMinutes($staleMinutes);
        $runs = SystemUpdateRun::query()
            ->where('status', 'running')
            ->where(function ($query) use ($threshold): void {
                $query->where('updated_at', '<=', $threshold)
                    ->orWhere(function ($query) use ($threshold): void {
                        $query->whereNull('updated_at')->where('started_at', '<=', $threshold);
                    });
            })
            ->get();

        foreach ($runs as $run) {
            $run->update([
                'status' => 'failed',
                'step' => 'failed',
                'error_message' => 'System update worker stopped before completing this run.',
                'finished_at' => now(),
                'log_lines' => array_merge($run->log_lines ?? [], [
                    'System update worker stopped before completing this run.',
                ]),
            ]);
        }

        return $runs->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function executeRun(SystemUpdateRun $run, bool $throwOnFailure = false): array
    {
        $run = $this->markRunRunning($run);

        try {
            if (! $run->package_path || ! is_file($run->package_path)) {
                throw new \UnexpectedValueException('Uploaded release package is missing.');
            }

            $result = $this->installer->installFromPackage(
                $run->tag,
                $run->package_path,
                (string) $run->package_sha256,
                fn (string $step, string $line): bool => $this->appendRunLog($run, $line, $step),
            );

            $run->update([
                'status' => 'completed',
                'step' => 'completed',
                'backup_path' => $result['backup_path'] ?? null,
                'package_path' => $result['package_path'] ?? $run->package_path,
                'log_lines' => array_merge($run->log_lines ?? [], ['Uploaded release package install completed.']),
                'finished_at' => now(),
            ]);

            return [
                'run_id' => $run->id,
                'status' => 'completed',
                'step' => 'completed',
                ...$result,
            ];
        } catch (Throwable $throwable) {
            $run->update([
                'status' => 'failed',
                'step' => 'rolled_back',
                'log_lines' => array_merge($run->log_lines ?? [], ['Uploaded release package install failed; rollback attempted.']),
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
            ]);

            if ($throwOnFailure) {
                throw $throwable;
            }

            report($throwable);

            return [
                'run_id' => $run->id,
                'status' => 'failed',
                'step' => 'rolled_back',
            ];
        }
    }

    private function markRunRunning(SystemUpdateRun $run): SystemUpdateRun
    {
        $run->refresh();

        if (! $this->isRunnableRun($run)) {
            throw new \UnexpectedValueException("System update run is not runnable from status [{$run->status}].");
        }

        $run->update([
            'status' => 'running',
            'step' => 'verifying',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
            'log_lines' => array_merge($run->log_lines ?? [], ['CLI worker started uploaded release package install.']),
        ]);

        return $run->refresh();
    }

    private function isQueueableRun(SystemUpdateRun $run): bool
    {
        if (in_array($run->status, ['pending', 'uploaded', 'failed'], true)) {
            return true;
        }

        return $this->isStaleRunningRun($run);
    }

    private function isRunnableRun(SystemUpdateRun $run): bool
    {
        if (in_array($run->status, ['pending', 'uploaded', 'queued', 'failed'], true)) {
            return true;
        }

        return $this->isStaleRunningRun($run);
    }

    private function isStaleRunningRun(SystemUpdateRun $run): bool
    {
        if ($run->status !== 'running') {
            return false;
        }

        $staleMinutes = (int) config('system_update.stale_run_minutes', 10);

        if ($staleMinutes < 1) {
            return false;
        }

        $lastTouchedAt = $run->updated_at ?? $run->started_at ?? $run->created_at;

        return $lastTouchedAt !== null && $lastTouchedAt->lte(now()->subMinutes($staleMinutes));
    }

    private function hasUpdate(string $currentVersion, string $latestVersion): bool
    {
        if ($latestVersion === '' || $latestVersion === 'unknown') {
            return false;
        }

        if ($currentVersion === '' || $currentVersion === 'unknown') {
            return true;
        }

        return version_compare(ltrim($latestVersion, 'v'), ltrim($currentVersion, 'v'), '>');
    }

    private function uploadedPackagePath(string $tag, int $runId, string $packageName): string
    {
        $root = rtrim((string) config('system_update.deployment_root', base_path()), DIRECTORY_SEPARATOR.'/\\');

        return $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'system_updates'
            .DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.$tag
            .DIRECTORY_SEPARATOR.'run-'.$runId.'-'.$packageName;
    }

    private function manualInstallCommand(string $packagePath, string $sha256): string
    {
        $root = rtrim((string) config('system_update.deployment_root', base_path()), DIRECTORY_SEPARATOR.'/\\');

        return sprintf(
            'cd %s && bash scripts/update-backend.sh %s %s',
            escapeshellarg($root),
            escapeshellarg($packagePath),
            escapeshellarg(strtolower($sha256))
        );
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function appendRunLog(SystemUpdateRun $run, string $line, string $step): bool
    {
        $run->refresh();
        $run->update([
            'step' => $step,
            'log_lines' => array_merge($run->log_lines ?? [], [$line]),
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentGitRelease(): array
    {
        $version = $this->runGitCommand(['describe', '--tags', '--always', '--dirty']);
        $commit = $this->runGitCommand(['rev-parse', '--short=12', 'HEAD']);
        $buildTime = $this->runGitCommand(['log', '-1', '--format=%cI']);

        if ($version === null && $commit === null) {
            return $this->unknownRelease();
        }

        return [
            'found' => true,
            'source' => 'git',
            'version' => $version ?? $commit ?? 'unknown',
            'tag' => $version,
            'commit' => $commit,
            'build_time' => $buildTime,
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runGitCommand(array $arguments): ?string
    {
        $process = new Process([(string) config('system_update.git_binary', 'git'), ...$arguments], base_path());
        $process->setTimeout(3);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output === '' ? null : $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownRelease(): array
    {
        return [
            'found' => false,
            'source' => null,
            'version' => 'unknown',
            'tag' => null,
            'commit' => null,
            'build_time' => null,
        ];
    }
}
