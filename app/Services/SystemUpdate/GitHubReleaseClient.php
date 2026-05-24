<?php

namespace App\Services\SystemUpdate;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\PendingRequest;
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

        $release = $this->githubApiRequest()
            ->get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest")
            ->throw(function ($response, RequestException $exception): void {
                if (
                    $response->status() === 403
                    && str_contains(
                        strtolower((string) ($response->json('message') ?? '')),
                        'rate limit',
                    )
                ) {
                    throw new GitHubRateLimitException;
                }

                throw $exception;
            })
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
        $manifest = $this->downloadManifest($this->assetDownloadUrl($manifestAsset));
        $manifestSha256 = $this->normalizeSha256((string) ($manifest['sha256'] ?? ''));
        $checksumSha256 = $this->parseChecksumSha256(
            $this->downloadChecksum($this->assetDownloadUrl($checksumAsset))
        );

        if (! hash_equals($manifestSha256, $checksumSha256)) {
            throw new RuntimeException('Release checksum asset does not match manifest sha256.');
        }

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
                'download_url' => $this->assetDownloadUrl($packageAsset),
                'size' => $packageAsset['size'] ?? null,
                'sha256' => $manifestSha256,
            ],
            'checksum' => [
                'name' => $checksumAsset['name'],
                'download_url' => $this->assetDownloadUrl($checksumAsset),
                'sha256' => $checksumSha256,
            ],
            'manifest' => [
                'name' => $manifestAsset['name'],
                'download_url' => $this->assetDownloadUrl($manifestAsset),
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

        if (! is_array($asset) || (empty($asset['url']) && empty($asset['browser_download_url']))) {
            throw new RuntimeException("Missing GitHub release asset: {$name}");
        }

        return $asset;
    }

    private function githubApiRequest(): PendingRequest
    {
        return $this->githubRequest()->acceptJson();
    }

    private function githubRequest(): PendingRequest
    {
        $request = Http::baseUrl('')
            ->timeout(30)
            ->retry(3, 500, null, false);
        $token = trim((string) config('system_update.github.token', ''));

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function assetDownloadUrl(array $asset): string
    {
        return (string) ($asset['url'] ?? $asset['browser_download_url']);
    }

    private function githubAssetRequest(): PendingRequest
    {
        return $this->githubRequest()->withHeaders([
            'Accept' => 'application/octet-stream',
        ])->withOptions([
            'version' => 1.1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadManifest(string $url): array
    {
        $manifest = $this->downloadAsset($url);
        $manifest = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('Release manifest is not valid JSON.');
        }

        return $manifest;
    }

    private function downloadChecksum(string $url): string
    {
        $checksum = $this->downloadAsset($url);

        if (trim($checksum) === '') {
            throw new RuntimeException('Release checksum asset is empty.');
        }

        return $checksum;
    }

    private function downloadAsset(string $url): string
    {
        $request = str_starts_with($url, 'https://api.github.com/')
            ? $this->githubAssetRequest()
            : Http::acceptJson()->timeout(30)->retry(3, 500);

        return $request->get($url)->throw()->body();
    }

    private function parseChecksumSha256(string $checksum): string
    {
        $checksum = trim($checksum);

        if (preg_match('/^([a-f0-9]{64})(?:\s+.+)?$/i', $checksum, $matches) !== 1) {
            throw new RuntimeException('Release checksum asset is invalid.');
        }

        return strtolower($matches[1]);
    }

    private function normalizeSha256(string $sha256): string
    {
        $sha256 = strtolower(trim($sha256));

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('Release manifest sha256 is invalid.');
        }

        return $sha256;
    }
}
