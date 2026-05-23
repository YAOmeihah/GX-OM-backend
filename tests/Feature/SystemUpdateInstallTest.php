<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SystemUpdate\InPlaceReleaseInstaller;
use App\Services\SystemUpdate\ReleasePackageVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemUpdateInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_preserves_env_storage_and_public_storage(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('preserve');
        $packagePath = $this->fixturePath('preserve-release.tar.gz');

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'public/old.txt' => 'stale public file',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->writeGzipTar($packagePath, [
            '.env.example' => 'APP_KEY=',
            'app/Services/Existing.php' => 'new service',
            'bootstrap/app.php' => '<?php',
            'config/app.php' => '<?php return [];',
            'database/migrations/example.php' => '<?php',
            'public/index.php' => 'new public index',
            'resources/views/.gitkeep' => '',
            'routes/api.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'artisan' => '#!/usr/bin/env php',
            'composer.lock' => '{}',
            'release.json' => '{"version":"1.2.4","tag":"v1.2.4"}',
        ]);

        Http::fake([
            'example.test/gx-om-backend-v1.2.4.tar.gz' => Http::response(file_get_contents($packagePath)),
        ]);

        $this->bindInstallerForRoot($root);
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $packagePath),
            'download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz',
            'confirmed' => true,
        ]);

        $response->assertAccepted();
        $this->assertSame('APP_KEY=existing', file_get_contents($root.'/.env'));
        $this->assertSame('local runtime', file_get_contents($root.'/storage/app/runtime.txt'));
        $this->assertSame('linked upload', file_get_contents($root.'/public/storage/upload.txt'));
        $this->assertSame('new service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertSame('new public index', file_get_contents($root.'/public/index.php'));
        $this->assertFileDoesNotExist($root.'/public/old.txt');
        $this->assertDatabaseHas('system_update_runs', [
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'completed',
            'package_sha256' => hash_file('sha256', $packagePath),
        ]);
    }

    public function test_failed_install_restores_backup_and_brings_application_up(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('rollback');
        $packagePath = $this->fixturePath('rollback-release.tar.gz');
        $commands = [];

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->writeGzipTar($packagePath, [
            '.env.example' => 'APP_KEY=',
            'app/Services/Existing.php' => 'new service',
            'bootstrap/app.php' => '<?php',
            'config/app.php' => '<?php return [];',
            'database/migrations/example.php' => '<?php',
            'public/index.php' => 'new public index',
            'resources/views/.gitkeep' => '',
            'routes/api.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'artisan' => '#!/usr/bin/env php',
            'composer.lock' => '{}',
            'release.json' => '{"version":"1.2.4","tag":"v1.2.4"}',
        ]);

        Http::fake([
            'example.test/gx-om-backend-v1.2.4.tar.gz' => Http::response(file_get_contents($packagePath)),
        ]);

        $this->bindInstallerForRoot($root, function (string $command) use (&$commands): void {
            $commands[] = $command;

            if ($command === 'migrate --force') {
                throw new RuntimeException('Migration failed.');
            }
        });
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $packagePath),
            'download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz',
            'confirmed' => true,
        ]);

        $response->assertStatus(500);
        $this->assertSame('old service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertSame('old public index', file_get_contents($root.'/public/index.php'));
        $this->assertSame('local runtime', file_get_contents($root.'/storage/app/runtime.txt'));
        $this->assertSame('linked upload', file_get_contents($root.'/public/storage/upload.txt'));
        $this->assertSame(['down', 'migrate --force', 'up'], $commands);
        $this->assertDatabaseHas('system_update_runs', [
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'failed',
            'package_sha256' => hash_file('sha256', $packagePath),
        ]);
    }

    private function bindInstallerForRoot(string $root, ?callable $commandRunner = null): void
    {
        $this->app->bind(InPlaceReleaseInstaller::class, fn ($app): InPlaceReleaseInstaller => new InPlaceReleaseInstaller(
            $app->make(ReleasePackageVerifier::class),
            $root,
            $commandRunner ?? fn (string $command): null => null,
        ));
    }

    private function actingAsAdmin(): User
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => '系统管理员',
            'is_system' => true,
        ]);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function fixtureDeploymentRoot(string $name): string
    {
        $root = $this->fixturePath('deployment-'.$name);

        if (is_dir($root)) {
            $this->removeDirectory($root);
        }

        mkdir($root, 0777, true);

        return $root;
    }

    private function fixturePath(string $name): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gx-om-system-update-install-tests';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeDeploymentRoot(string $root, array $files): void
    {
        foreach ($files as $relativePath => $contents) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents);
        }
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function writeGzipTar(string $path, array $entries): void
    {
        $tar = '';

        foreach ($entries as $name => $contents) {
            $tar .= $this->tarHeader($name, strlen($contents));
            $tar .= $contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512);
        }

        $tar .= str_repeat("\0", 1024);

        file_put_contents($path, gzencode($tar));
    }

    private function tarHeader(string $name, int $size): string
    {
        $header = str_pad($name, 100, "\0");
        $header .= str_pad('0000644', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad('00000000000', 12, "\0");
        $header .= '        ';
        $header .= '0';
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 155);
        $header .= str_repeat("\0", 12);
        $header = str_pad($header, 512, "\0");

        $checksum = 0;
        for ($index = 0; $index < 512; $index++) {
            $checksum += ord($header[$index]);
        }

        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
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

            unlink($path);
        }

        rmdir($directory);
    }
}
