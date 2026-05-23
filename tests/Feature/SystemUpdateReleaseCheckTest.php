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
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/pkg.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/*' => Http::response('{"version":"1.2.4"}'),
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
            $response->assertJsonPath('data.has_update', true);
        } finally {
            if ($originalReleaseJson === null) {
                @unlink($releaseJsonPath);
            } else {
                file_put_contents($releaseJsonPath, $originalReleaseJson);
            }
        }
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
