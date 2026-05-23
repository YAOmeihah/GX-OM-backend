<?php

namespace App\Services\SystemUpdate;

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
            return [
                'found' => false,
                'version' => 'unknown',
                'tag' => null,
                'commit' => null,
                'build_time' => null,
            ];
        }

        $metadata = json_decode((string) file_get_contents($path), true);

        if (! is_array($metadata)) {
            return [
                'found' => false,
                'version' => 'unknown',
                'tag' => null,
                'commit' => null,
                'build_time' => null,
            ];
        }

        return [
            'found' => true,
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
}
