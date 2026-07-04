<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SystemUpdate\GitHubReleaseClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GitHubReleaseClientTest extends TestCase
{
    public function test_latest_release_retries_transient_asset_connection_failures(): void
    {
        $manifestAttempts = 0;

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    [
                        'name' => 'release-manifest.json',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/1',
                    ],
                    [
                        'name' => 'gx-om-backend-v1.2.4.tar.gz',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/2',
                    ],
                    [
                        'name' => 'gx-om-backend-v1.2.4.tar.gz.sha256',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/3',
                    ],
                ],
            ]),
            'api.github.com/repos/acme/app/releases/assets/1' => function () use (&$manifestAttempts) {
                $manifestAttempts++;

                if ($manifestAttempts === 1) {
                    throw new ConnectionException('Connection was reset.');
                }

                return Http::response(json_encode([
                    'version' => '1.2.4',
                    'sha256' => str_repeat('a', 64),
                ], JSON_THROW_ON_ERROR));
            },
            'api.github.com/repos/acme/app/releases/assets/3' => Http::response(str_repeat('a', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
        ]);

        $release = (new GitHubReleaseClient)->latestRelease();

        $this->assertSame('v1.2.4', $release['tag']);
        $this->assertSame(2, $manifestAttempts);
    }

    public function test_latest_release_downloads_metadata_assets_through_authenticated_asset_api(): void
    {
        config(['system_update.github.token' => 'github-token-for-tests']);

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    [
                        'name' => 'release-manifest.json',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/1',
                        'browser_download_url' => 'https://github.com/acme/app/releases/download/v1.2.4/release-manifest.json',
                    ],
                    [
                        'name' => 'gx-om-backend-v1.2.4.tar.gz',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/2',
                        'browser_download_url' => 'https://github.com/acme/app/releases/download/v1.2.4/gx-om-backend-v1.2.4.tar.gz',
                    ],
                    [
                        'name' => 'gx-om-backend-v1.2.4.tar.gz.sha256',
                        'url' => 'https://api.github.com/repos/acme/app/releases/assets/3',
                        'browser_download_url' => 'https://github.com/acme/app/releases/download/v1.2.4/gx-om-backend-v1.2.4.tar.gz.sha256',
                    ],
                ],
            ]),
            'api.github.com/repos/acme/app/releases/assets/1' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR)),
            'api.github.com/repos/acme/app/releases/assets/3' => Http::response(str_repeat('a', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            'github.com/*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $release = (new GitHubReleaseClient)->latestRelease();

        $this->assertSame('v1.2.4', $release['tag']);
        $this->assertSame('https://api.github.com/repos/acme/app/releases/assets/2', $release['package']['download_url']);
        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/app/releases/assets/1'
                && $request->hasHeader('Authorization', 'Bearer github-token-for-tests')
                && $request->hasHeader('Accept', 'application/octet-stream')
                && $request->header('Accept') === ['application/octet-stream']
        );
        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/app/releases/assets/3'
                && $request->hasHeader('Authorization', 'Bearer github-token-for-tests')
                && $request->hasHeader('Accept', 'application/octet-stream')
        );
    }

    public function test_latest_release_uses_configured_github_token_for_api_request(): void
    {
        config(['system_update.github.token' => 'github-token-for-tests']);

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR)),
            'example.test/gx-om-backend-v1.2.4.tar.gz.sha256' => Http::response(str_repeat('a', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
        ]);

        (new GitHubReleaseClient)->latestRelease();

        Http::assertSent(
            fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/')
                && $request->hasHeader('Authorization', 'Bearer github-token-for-tests')
        );
    }

    public function test_latest_release_rejects_checksum_asset_that_does_not_match_manifest_sha256(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR)),
            'example.test/gx-om-backend-v1.2.4.tar.gz.sha256' => Http::response(str_repeat('b', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Release checksum asset does not match manifest sha256.');

        (new GitHubReleaseClient)->latestRelease();
    }
}
