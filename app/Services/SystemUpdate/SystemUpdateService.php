<?php

namespace App\Services\SystemUpdate;

use Symfony\Component\Process\Process;

class SystemUpdateService
{
    public function __construct(
        private readonly GitHubReleaseClient $githubReleaseClient,
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
