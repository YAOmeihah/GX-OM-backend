<?php

namespace App\Services\SystemUpdate;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubReleaseClient
{
    /**
     * @return array<string, mixed>
     */
    public function latestRelease(): array
    {
        $owner = config('system_update.github.owner');
        $repo = config('system_update.github.repo');

        $release = Http::acceptJson()
            ->get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest")
            ->throw()
            ->json();

        if (($release['draft'] ?? false) === true) {
            throw new RuntimeException('Latest GitHub release is a draft.');
        }

        $tag = (string) ($release['tag_name'] ?? '');
        $packageName = "gx-om-backend-{$tag}.tar.gz";
        $assets = collect($release['assets'] ?? []);

        $manifestAsset = $this->findAsset($assets, 'release-manifest.json');
        $packageAsset = $this->findAsset($assets, $packageName);
        $checksumAsset = $this->findAsset($assets, "{$packageName}.sha256");
        $manifest = $this->downloadManifest((string) $manifestAsset['browser_download_url']);

        return [
            'tag' => $tag,
            'version' => (string) ($manifest['version'] ?? ltrim($tag, 'v')),
            'release_name' => $manifest['release_name'] ?? ($release['name'] ?? null),
            'commit' => $manifest['commit'] ?? null,
            'build_time' => $manifest['build_time'] ?? null,
            'published_at' => $release['published_at'] ?? null,
            'prerelease' => (bool) ($release['prerelease'] ?? false),
            'package' => [
                'name' => $packageAsset['name'],
                'download_url' => $packageAsset['browser_download_url'],
                'size' => $packageAsset['size'] ?? null,
                'sha256' => $manifest['sha256'] ?? null,
            ],
            'checksum' => [
                'name' => $checksumAsset['name'],
                'download_url' => $checksumAsset['browser_download_url'],
            ],
            'manifest' => [
                'name' => $manifestAsset['name'],
                'download_url' => $manifestAsset['browser_download_url'],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function findAsset($assets, string $name): array
    {
        $asset = $assets->first(fn (array $asset): bool => ($asset['name'] ?? null) === $name);

        if (! is_array($asset) || empty($asset['browser_download_url'])) {
            throw new RuntimeException("Missing GitHub release asset: {$name}");
        }

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadManifest(string $url): array
    {
        $manifest = Http::acceptJson()->get($url)->throw()->json();

        if (! is_array($manifest)) {
            throw new RuntimeException('Release manifest is not valid JSON.');
        }

        return $manifest;
    }
}
