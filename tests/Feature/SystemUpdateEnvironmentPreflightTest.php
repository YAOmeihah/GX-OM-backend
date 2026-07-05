<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUpdateEnvironmentPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_the_local_environment_status(): void
    {
        $root = $this->prepareDeploymentRoot('preflight-pass');
        file_put_contents($root.'/artisan', '<?php');
        config()->set('system_update.deployment_root', $root);

        $this->actingAsAdminWithPermission();

        $this->getJson('/api/system-updates/preflight')
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.checks.0.id', 'workspace_writable')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'passed',
                    'checked_at',
                    'checks' => [
                        [
                            'id',
                            'label',
                            'passed',
                            'detail',
                            'remediation',
                        ],
                    ],
                ],
            ]);
    }

    public function test_preflight_accepts_an_existing_public_storage_link_when_the_probe_path_is_blocked(): void
    {
        $root = $this->prepareDeploymentRoot('preflight-existing-storage-link');
        file_put_contents($root.'/artisan', '<?php');
        mkdir($root.'/public', 0777, true);
        mkdir($root.'/storage/app/public', 0777, true);
        mkdir($root.'/storage/app/system_updates/.preflight-link', 0777, true);

        $this->createStorageLink($root.'/storage/app/public', $root.'/public/storage');

        config()->set('system_update.deployment_root', $root);

        $this->actingAsAdminWithPermission();

        $this->getJson('/api/system-updates/preflight')
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.checks.7.id', 'symlink_supported')
            ->assertJsonPath('data.checks.7.passed', true)
            ->assertJsonPath('data.checks.7.detail', 'public/storage 已连接到 storage/app/public。');
    }

    public function test_upload_is_refused_when_the_environment_preflight_fails(): void
    {
        $root = $this->prepareDeploymentRoot('preflight-fail', false);
        config()->set('system_update.deployment_root', $root);
        $packagePath = $root.'/gx-om-backend-v1.2.4.tar.gz';
        file_put_contents($packagePath, 'package');

        $this->actingAsAdminWithPermission();

        Http::fake();

        $this->post('/api/system-updates/uploads', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $packagePath),
            'package' => new \Illuminate\Http\UploadedFile(
                $packagePath,
                'gx-om-backend-v1.2.4.tar.gz',
                'application/gzip',
                null,
                true
            ),
        ], ['Accept' => 'application/json'])
            ->assertStatus(412)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.preflight.passed', false)
            ->assertJsonPath('data.preflight.checks.0.id', 'workspace_writable');

        Http::assertNothingSent();
    }

    private function actingAsAdminWithPermission(): User
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => '系统管理员',
            'is_system' => true,
        ]);

        $permission = Permission::firstOrCreate([
            'slug' => 'system-updates.manage',
        ], [
            'name' => '系统更新',
            'module' => 'system',
            'description' => '检查、上传、排队和回滚系统更新',
        ]);

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function prepareDeploymentRoot(string $name, bool $withWorkspace = true): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gx-om-system-update-preflight-tests'.DIRECTORY_SEPARATOR.$name;

        $this->removeDirectory($root);
        mkdir($root, 0777, true);

        if ($withWorkspace) {
            mkdir($root.'/storage/app/system_updates', 0777, true);
        }

        return $root;
    }

    private function createStorageLink(string $target, string $link): void
    {
        if (@symlink($target, $link)) {
            return;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            exec('cmd /C mklink /J '.escapeshellarg($link).' '.escapeshellarg($target), $output, $exitCode);

            if ($exitCode === 0) {
                return;
            }
        }

        $this->fail('Unable to create a storage link fixture.');
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = array_diff(scandir($directory) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
