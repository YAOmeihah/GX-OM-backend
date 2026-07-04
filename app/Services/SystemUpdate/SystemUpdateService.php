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
        private readonly SystemUpdateDeferredRunner $deferredRunner,
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
    public function install(array $payload): array
    {
        $this->environmentPreflight->ensureReady();

        $tag = $payload['tag'];
        $latest = $this->githubReleaseClient->latestRelease();

        if (($latest['tag'] ?? null) !== $tag) {
            throw new \UnexpectedValueException('Requested release tag does not match the latest release.');
        }

        $downloadUrl = (string) ($latest['package']['download_url'] ?? '');

        if ($downloadUrl === '') {
            throw new \UnexpectedValueException('Release package download URL is missing.');
        }

        $run = SystemUpdateRun::create([
            'actor_user_id' => auth()->id(),
            'tag' => $tag,
            'version' => ltrim($tag, 'v'),
            'status' => 'running',
            'step' => 'installing',
            'package_sha256' => strtolower($payload['sha256']),
            'started_at' => now(),
            'log_lines' => ['Started system update install.'],
        ]);

        $sha256 = strtolower($payload['sha256']);
        $this->deferredRunner->afterResponse(
            fn (): array => $this->completeTrustedReleaseInstall($run->id, $tag, $downloadUrl, $sha256)
        );

        return [
            'run_id' => $run->id,
            'status' => 'running',
            'step' => 'installing',
        ];
    }

    /**
     * @param  array{tag: string, sha256: string}  $payload
     * @return array<string, mixed>
     */
    public function queueUploadedPackage(array $payload, ?UploadedFile $package): array
    {
        if (! $package instanceof UploadedFile) {
            throw new \UnexpectedValueException('Release package file is missing.');
        }

        $this->environmentPreflight->ensureReady();

        $tag = $payload['tag'];
        $providedSha256 = strtolower($payload['sha256']);
        $expectedName = "gx-om-backend-{$tag}.tar.gz";
        $originalName = $package->getClientOriginalName();

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
            'status' => 'pending',
            'step' => 'uploaded',
            'package_sha256' => $providedSha256,
            'metadata' => [
                'source' => 'upload',
                'package_name' => $expectedName,
                'uploaded_size' => $package->getSize(),
            ],
            'started_at' => now(),
            'log_lines' => ['Uploaded release package.', 'Release package is ready for manual install.'],
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function installUploadedPackage(SystemUpdateRun $run): array
    {
        $run = $this->markUploadedPackageInstallRunning($run);

        $this->deferredRunner->afterResponse(
            fn (): array => $this->completeUploadedPackageInstall($run->id)
        );

        return [
            'run_id' => $run->id,
            'status' => 'running',
            'step' => 'installing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function installUploadedPackageNow(SystemUpdateRun $run): array
    {
        $run = $this->markUploadedPackageInstallRunning($run, true);

        return $this->completeUploadedPackageInstall($run->id, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeUploadedPackageInstall(int $runId, bool $throwOnFailure = false): array
    {
        $run = SystemUpdateRun::query()->find($runId);

        if (! $run) {
            return [
                'run_id' => $runId,
                'status' => 'failed',
                'step' => 'missing',
            ];
        }

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

    /**
     * @return array<string, mixed>
     */
    private function completeTrustedReleaseInstall(
        int $runId,
        string $tag,
        string $downloadUrl,
        string $sha256
    ): array {
        $run = SystemUpdateRun::query()->find($runId);

        if (! $run) {
            return [
                'run_id' => $runId,
                'status' => 'failed',
                'step' => 'missing',
            ];
        }

        try {
            $result = $this->installer->install(
                $tag,
                $downloadUrl,
                $sha256,
                fn (string $step, string $line): bool => $this->appendRunLog($run, $line, $step),
            );

            $run->update([
                'status' => 'completed',
                'step' => 'completed',
                'backup_path' => $result['backup_path'] ?? null,
                'package_path' => $result['package_path'] ?? null,
                'metadata' => ['download_url' => $downloadUrl],
                'log_lines' => array_merge($run->log_lines ?? [], ['System update install completed.']),
                'finished_at' => now(),
            ]);

            return ['run_id' => $run->id, 'status' => 'completed', 'step' => 'completed', ...$result];
        } catch (Throwable $throwable) {
            $run->update([
                'status' => 'failed',
                'step' => 'rolled_back',
                'metadata' => ['download_url' => $downloadUrl],
                'log_lines' => array_merge($run->log_lines ?? [], ['System update install failed; rollback attempted.']),
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
            ]);

            report($throwable);

            return [
                'run_id' => $run->id,
                'status' => 'failed',
                'step' => 'rolled_back',
            ];
        }
    }

    private function markUploadedPackageInstallRunning(SystemUpdateRun $run, bool $allowRunning = false): SystemUpdateRun
    {
        $this->environmentPreflight->ensureReady();
        $run->refresh();

        $wasRunning = $run->status === 'running';
        $allowedStatuses = ['pending', 'failed'];

        if ($allowRunning || $this->isStaleUploadedPackageRun($run)) {
            $allowedStatuses[] = 'running';
        }

        if (! in_array($run->status, $allowedStatuses, true)) {
            throw new \UnexpectedValueException("System update run is not installable from status [{$run->status}].");
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
            'status' => 'running',
            'step' => 'installing',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
            'log_lines' => array_merge($run->log_lines ?? [], [
                $wasRunning
                    ? 'Restarted stale uploaded release package install.'
                    : 'Started uploaded release package install.',
            ]),
        ]);

        return $run->refresh();
    }

    private function isStaleUploadedPackageRun(SystemUpdateRun $run): bool
    {
        if ($run->status !== 'running' || ! $run->package_path) {
            return false;
        }

        if (($run->metadata['source'] ?? null) !== 'upload') {
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

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function appendRunLog(SystemUpdateRun $run, string $line, string $step): bool
    {
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
