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
        private readonly SystemUpdateProcessStarter $processStarter,
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

        try {
            $result = $this->installer->install($tag, $downloadUrl, $payload['sha256']);

            $run->update([
                'status' => 'completed',
                'step' => 'completed',
                'backup_path' => $result['backup_path'] ?? null,
                'package_path' => $result['package_path'] ?? null,
                'metadata' => ['download_url' => $downloadUrl],
                'log_lines' => ['Started system update install.', 'System update install completed.'],
                'finished_at' => now(),
            ]);

            return ['run_id' => $run->id, ...$result];
        } catch (Throwable $throwable) {
            $run->update([
                'status' => 'failed',
                'step' => 'rolled_back',
                'metadata' => ['download_url' => $downloadUrl],
                'log_lines' => ['Started system update install.', 'System update install failed; rollback attempted.'],
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
            ]);

            throw $throwable;
        }
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
        $latest = $this->githubReleaseClient->latestRelease();

        if (($latest['tag'] ?? null) !== $tag) {
            throw new \UnexpectedValueException('Requested release tag does not match the latest release.');
        }

        $expectedSha256 = strtolower((string) ($latest['package']['sha256'] ?? ''));
        $providedSha256 = strtolower($payload['sha256']);

        if ($expectedSha256 === '' || $expectedSha256 !== $providedSha256) {
            throw new \UnexpectedValueException('Release package SHA256 does not match the latest release metadata.');
        }

        $expectedName = (string) ($latest['package']['name'] ?? "gx-om-backend-{$tag}.tar.gz");
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
            'log_lines' => ['Uploaded release package.', 'Queued uploaded release package install.'],
        ]);

        $destination = $this->uploadedPackagePath($tag, $run->id, $expectedName);
        $this->ensureDirectory(dirname($destination));
        $package->move(dirname($destination), basename($destination));

        $run->update(['package_path' => $destination]);
        $run->refresh();

        $this->processStarter->start($run);

        return [
            'run_id' => $run->id,
            'status' => $run->status,
        ];
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
