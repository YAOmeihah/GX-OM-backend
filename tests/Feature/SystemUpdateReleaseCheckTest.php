<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUpdateReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_endpoint_reports_new_release_against_local_version(): void
    {
        $this->actingAsAdmin();
        $releaseJsonPath = base_path('release.json');
        $originalReleaseJson = is_file($releaseJsonPath) ? file_get_contents($releaseJsonPath) : null;

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'name' => 'GX-OM Backend v1.2.4',
                'body' => "## 更新内容\n\n- 修复系统更新 worker",
                'html_url' => 'https://github.com/YAOmeihah/GX-OM-backend/releases/tag/v1.2.4',
                'published_at' => '2026-07-05T10:30:00Z',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/pkg.tar.gz', 'size' => 123_456],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => str_repeat('c', 64),
            ], JSON_THROW_ON_ERROR)),
            'example.test/pkg.tar.gz.sha256' => Http::response(str_repeat('c', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            'example.test/pkg.tar.gz' => function (): void {
                $this->fail('Checking updates must not download the release package.');
            },
        ]);

        try {
            file_put_contents($releaseJsonPath, json_encode([
                'version' => '1.2.3',
                'tag' => 'v1.2.3',
                'commit' => 'local-commit',
                'build_time' => '2026-05-22T12:00:00Z',
            ], JSON_THROW_ON_ERROR));

            $response = $this->getJson('/api/system-updates/check');

            $response->assertOk();
            $response->assertJsonPath('data.current.version', '1.2.3');
            $response->assertJsonPath('data.latest.tag', 'v1.2.4');
            $response->assertJsonPath('data.latest.release_name', 'GX-OM Backend v1.2.4');
            $response->assertJsonPath('data.latest.body', "## 更新内容\n\n- 修复系统更新 worker");
            $response->assertJsonPath('data.latest.html_url', 'https://github.com/YAOmeihah/GX-OM-backend/releases/tag/v1.2.4');
            $response->assertJsonPath('data.latest.package.name', 'gx-om-backend-v1.2.4.tar.gz');
            $response->assertJsonPath('data.latest.package.size', 123_456);
            $response->assertJsonPath('data.latest.package.sha256', str_repeat('c', 64));
            $response->assertJsonPath('data.latest.script_install_command', function (string $command): bool {
                return str_contains($command, 'SYSTEM_UPDATE_GITHUB_TOKEN')
                    && str_contains($command, 'https://api.github.com/repos/YAOmeihah/GX-OM-backend/contents/scripts/update-backend.sh?ref=v1.2.4')
                    && str_contains($command, 'application/vnd.github.raw+json')
                    && str_contains($command, '| bash -s -- --tag v1.2.4');
            });
            $response->assertJsonMissingPath('data.latest.package.download_url');
            $response->assertJsonPath('data.has_update', true);

            Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://example.test/pkg.tar.gz');
        } finally {
            if ($originalReleaseJson === null) {
                @unlink($releaseJsonPath);
            } else {
                file_put_contents($releaseJsonPath, $originalReleaseJson);
            }
        }
    }

    public function test_check_endpoint_returns_readable_error_when_github_rate_limit_is_hit(): void
    {
        $this->actingAsAdmin();

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'message' => 'API rate limit exceeded for 103.62.49.138.',
                'documentation_url' => 'https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting',
            ], 403),
        ]);

        $this->getJson('/api/system-updates/check')
            ->assertStatus(429)
            ->assertJsonPath('message', 'GitHub API rate limit exceeded. Configure SYSTEM_UPDATE_GITHUB_TOKEN or try again later.');
    }

    private function actingAsAdmin(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => '系统管理员',
            'is_system' => true,
        ]);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);
    }
}
