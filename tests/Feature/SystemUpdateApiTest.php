<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_update_routes_require_manage_permission(): void
    {
        $this->actingAs($this->storeStaff());

        $this->getJson('/api/system-updates/current')->assertForbidden();
        $this->getJson('/api/system-updates/check')->assertForbidden();
    }

    public function test_removed_system_update_execution_routes_return_not_found(): void
    {
        $this->actingAsAdminWithPermission();

        $this->getJson('/api/system-updates/preflight')->assertNotFound();
        $this->postJson('/api/system-updates/install')->assertNotFound();
        $this->postJson('/api/system-updates/uploads')->assertNotFound();
        $this->getJson('/api/system-updates/runs')->assertNotFound();
        $this->getJson('/api/system-updates/runs/1')->assertNotFound();
        $this->postJson('/api/system-updates/runs/1/queue')->assertNotFound();
        $this->postJson('/api/system-updates/runs/1/install')->assertNotFound();
        $this->postJson('/api/system-updates/rollback')->assertNotFound();
    }

    public function test_system_update_check_routes_return_expected_payloads(): void
    {
        $this->actingAsAdminWithPermission();
        $releaseJsonPath = base_path('release.json');
        $originalReleaseJson = is_file($releaseJsonPath) ? file_get_contents($releaseJsonPath) : null;

        try {
            file_put_contents($releaseJsonPath, json_encode([
                'version' => '1.2.3',
                'tag' => 'v1.2.3',
                'commit' => 'local-commit',
                'build_time' => '2026-05-22T12:00:00Z',
            ], JSON_THROW_ON_ERROR));

            \Illuminate\Support\Facades\Http::fake([
                'api.github.com/repos/*/releases/latest' => \Illuminate\Support\Facades\Http::response([
                    'tag_name' => 'v1.2.4',
                    'name' => 'GX-OM Backend v1.2.4',
                    'body' => 'Release notes',
                    'html_url' => 'https://github.com/YAOmeihah/GX-OM-backend/releases/tag/v1.2.4',
                    'draft' => false,
                    'prerelease' => false,
                    'assets' => [
                        ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                        ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                        ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz.sha256'],
                    ],
                ]),
                'example.test/release-manifest.json' => \Illuminate\Support\Facades\Http::response(json_encode([
                    'version' => '1.2.4',
                    'sha256' => str_repeat('c', 64),
                ], JSON_THROW_ON_ERROR)),
                'example.test/gx-om-backend-v1.2.4.tar.gz.sha256' => \Illuminate\Support\Facades\Http::response(str_repeat('c', 64).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            ]);

            $this->getJson('/api/system-updates/current')
                ->assertOk()
                ->assertJsonPath('data.version', '1.2.3');

            $this->getJson('/api/system-updates/check')
                ->assertOk()
                ->assertJsonPath('data.latest.tag', 'v1.2.4')
                ->assertJsonPath('data.latest.html_url', 'https://github.com/YAOmeihah/GX-OM-backend/releases/tag/v1.2.4')
                ->assertJsonMissingPath('data.latest.package.download_url')
                ->assertJsonMissingPath('data.latest.script_install_command')
                ->assertJsonPath('data.has_update', true);
        } finally {
            if ($originalReleaseJson === null) {
                @unlink($releaseJsonPath);
            } else {
                file_put_contents($releaseJsonPath, $originalReleaseJson);
            }
        }
    }

    public function test_current_release_falls_back_to_git_metadata_when_release_json_is_missing(): void
    {
        $this->actingAsAdminWithPermission();

        $releaseJsonPath = base_path('release.json');
        $originalReleaseJson = is_file($releaseJsonPath) ? file_get_contents($releaseJsonPath) : null;
        $fakeGitPath = $this->createFakeGitBinary();
        config()->set('system_update.git_binary', $fakeGitPath);

        try {
            @unlink($releaseJsonPath);

            $this->getJson('/api/system-updates/current')
                ->assertOk()
                ->assertJsonPath('data.found', true)
                ->assertJsonPath('data.source', 'git')
                ->assertJsonPath('data.version', 'git version')
                ->assertJsonPath('data.tag', 'git version')
                ->assertJsonPath('data.commit', 'git commit')
                ->assertJsonPath('data.build_time', 'git date');
        } finally {
            if ($originalReleaseJson !== null) {
                file_put_contents($releaseJsonPath, $originalReleaseJson);
            }

            @unlink($fakeGitPath);
        }
    }

    private function createFakeGitBinary(): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $path = $directory.DIRECTORY_SEPARATOR.'fake-git.bat';
            file_put_contents($path, implode(PHP_EOL, [
                '@echo off',
                'if "%1"=="describe" echo git version',
                'if "%1"=="rev-parse" echo git commit',
                'if "%1"=="log" echo git date',
            ]).PHP_EOL);

            return $path;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'fake-git';
        file_put_contents($path, implode(PHP_EOL, [
            '#!/usr/bin/env sh',
            'case "$1" in',
            '  describe) echo "git version" ;;',
            '  rev-parse) echo "git commit" ;;',
            '  log) echo "git date" ;;',
            'esac',
        ]).PHP_EOL);
        chmod($path, 0755);

        return $path;
    }

    private function actingAsAdminWithPermission(): User
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => '系统管理员',
            'is_system' => true,
        ]);

        $permission = \App\Models\Permission::firstOrCreate([
            'slug' => 'system-updates.manage',
        ], [
            'name' => '系统更新',
            'module' => 'system',
            'description' => '检查系统当前版本和 GitHub 最新版本',
        ]);

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function storeStaff(): User
    {
        $storeStaffRole = Role::firstOrCreate(['slug' => 'store_staff'], [
            'name' => '店员',
            'is_system' => false,
        ]);

        $storeStaff = User::factory()->create();
        $storeStaff->roles()->attach($storeStaffRole);

        return $storeStaff;
    }
}
