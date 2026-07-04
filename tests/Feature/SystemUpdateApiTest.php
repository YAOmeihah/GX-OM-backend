<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemUpdateRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->getJson('/api/system-updates/preflight')->assertForbidden();
        $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => str_repeat('a', 64),
            'confirmed' => true,
        ])->assertForbidden();
        $this->getJson('/api/system-updates/runs')->assertForbidden();
        $this->postJson('/api/system-updates/rollback', [
            'run_id' => 1,
        ])->assertForbidden();
    }

    public function test_system_update_routes_return_expected_payloads(): void
    {
        $admin = $this->actingAsAdminWithPermission();
        $releaseJsonPath = base_path('release.json');
        $originalReleaseJson = is_file($releaseJsonPath) ? file_get_contents($releaseJsonPath) : null;

        $run = SystemUpdateRun::query()->create([
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'completed',
            'step' => 'completed',
            'metadata' => ['download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Started system update install.', 'System update install completed.'],
            'backup_path' => '/tmp/backups/v1.2.4',
            'package_path' => '/tmp/downloads/gx-om-backend-v1.2.4.tar.gz',
            'package_sha256' => str_repeat('b', 64),
            'started_at' => Carbon::parse('2026-05-23 10:00:00'),
            'finished_at' => Carbon::parse('2026-05-23 10:05:00'),
        ]);

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

            $this->mock(\App\Services\SystemUpdate\InPlaceReleaseInstaller::class, function ($mock) use ($run): void {
                $mock->shouldReceive('rollback')
                    ->once()
                    ->with($run->backup_path)
                    ->andReturn(['backup_path' => $run->backup_path]);
            });

            $this->getJson('/api/system-updates/current')
                ->assertOk()
                ->assertJsonPath('data.version', '1.2.3');

            $this->getJson('/api/system-updates/check')
                ->assertOk()
                ->assertJsonPath('data.latest.tag', 'v1.2.4')
                ->assertJsonPath('data.has_update', true);

            $this->getJson('/api/system-updates/preflight')
                ->assertOk()
                ->assertJsonPath('data.passed', true)
                ->assertJsonPath('data.checks.0.id', 'workspace_writable');

            $this->getJson('/api/system-updates/runs')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $run->id);

            $this->getJson('/api/system-updates/runs/'.$run->id)
                ->assertOk()
                ->assertJsonPath('data.tag', 'v1.2.4');

            $this->postJson('/api/system-updates/rollback', ['run_id' => $run->id])
                ->assertOk()
                ->assertJsonPath('data.run_id', $run->id)
                ->assertJsonPath('data.status', 'rolled_back');
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
            'description' => '检查、下载、安装和回滚系统更新',
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
