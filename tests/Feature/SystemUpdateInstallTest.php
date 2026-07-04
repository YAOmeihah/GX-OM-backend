<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemUpdateRun;
use App\Models\User;
use App\Services\SystemUpdate\InPlaceReleaseInstaller;
use App\Services\SystemUpdate\ReleasePackageVerifier;
use App\Services\SystemUpdate\SystemUpdateProcessStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemUpdateInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_install_stores_uploaded_release_package_without_starting_background_process(): void
    {
        Storage::fake();
        $packagePath = $this->fixturePath('uploaded-release.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);

        Http::fake();

        $this->mock(SystemUpdateProcessStarter::class, function ($mock): void {
            $mock->shouldReceive('start')
                ->never();
        });

        $admin = $this->actingAsAdmin();

        $response = $this->post('/api/system-updates/install-upload', [
            'tag' => 'v1.2.4',
            'sha256' => $sha256,
            'confirmed' => '1',
            'package' => new UploadedFile(
                $packagePath,
                'gx-om-backend-v1.2.4.tar.gz',
                'application/gzip',
                null,
                true
            ),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.step', 'uploaded');

        $run = SystemUpdateRun::query()->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $run->actor_user_id);
        $this->assertSame('v1.2.4', $run->tag);
        $this->assertSame('1.2.4', $run->version);
        $this->assertSame('pending', $run->status);
        $this->assertSame('uploaded', $run->step);
        $this->assertSame($sha256, $run->package_sha256);
        $this->assertSame('upload', $run->metadata['source']);
        $this->assertSame('gx-om-backend-v1.2.4.tar.gz', $run->metadata['package_name']);
        $this->assertFileExists((string) $run->package_path);
        $this->assertSame($sha256, hash_file('sha256', (string) $run->package_path));

        Http::assertNothingSent();
    }

    public function test_start_uploaded_install_endpoint_installs_pending_package_without_background_process(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('uploaded-endpoint');
        $packagePath = $this->fixturePath('uploaded-endpoint-release.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);
        $commands = [];

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->bindInstallerForRoot($root, function (string $command) use (&$commands): void {
            $commands[] = $command;
        });
        $admin = $this->actingAsAdmin();

        $run = SystemUpdateRun::query()->create([
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'pending',
            'step' => 'uploaded',
            'metadata' => ['source' => 'upload', 'package_name' => 'gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Uploaded release package.'],
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
            'started_at' => now(),
        ]);

        $response = $this->postJson("/api/system-updates/runs/{$run->id}/install", [
            'confirmed' => true,
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.run_id', $run->id)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('new service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertSame('local runtime', file_get_contents($root.'/storage/app/runtime.txt'));
        $this->assertSame('linked upload', file_get_contents($root.'/public/storage/upload.txt'));
        $this->assertSame([
            'down',
            'migrate --force',
            'optimize:clear',
            'storage:link --force',
            'up',
        ], $commands);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', $run->step);
        $this->assertNotNull($run->backup_path);
        $this->assertContains('Started uploaded release package install.', $run->log_lines);
        $this->assertContains('Uploaded release package install completed.', $run->log_lines);
        $this->assertContains('Replacing managed application files.', $run->log_lines);
    }

    public function test_artisan_system_update_run_installs_queued_uploaded_package(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('uploaded-command');
        $packagePath = $this->fixturePath('uploaded-command-release.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);
        $commands = [];

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->bindInstallerForRoot($root, function (string $command) use (&$commands): void {
            $commands[] = $command;
        });

        $run = SystemUpdateRun::query()->create([
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'pending',
            'step' => 'uploaded',
            'metadata' => ['source' => 'upload', 'package_name' => 'gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Uploaded release package.'],
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
            'started_at' => now(),
        ]);

        $exitCode = Artisan::call('system-update:run', ['run' => $run->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('new service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertSame('local runtime', file_get_contents($root.'/storage/app/runtime.txt'));
        $this->assertSame('linked upload', file_get_contents($root.'/public/storage/upload.txt'));
        $this->assertSame([
            'down',
            'migrate --force',
            'optimize:clear',
            'storage:link --force',
            'up',
        ], $commands);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', $run->step);
        $this->assertNotNull($run->backup_path);
        $this->assertContains('Uploaded release package install completed.', $run->log_lines);
    }

    public function test_install_preserves_env_storage_and_public_storage(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('preserve');
        $packagePath = $this->fixturePath('preserve-release.tar.gz');
        $commands = [];

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
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => hash_file('sha256', $packagePath),
            ], JSON_THROW_ON_ERROR)),
            'example.test/pkg.tar.gz.sha256' => Http::response(hash_file('sha256', $packagePath).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            'example.test/gx-om-backend-v1.2.4.tar.gz' => Http::response(file_get_contents($packagePath)),
        ]);

        $this->bindInstallerForRoot($root, function (string $command) use (&$commands): void {
            $commands[] = $command;
        });
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $packagePath),
            'confirmed' => true,
        ]);

        $response->assertAccepted();
        $this->assertSame('APP_KEY=existing', file_get_contents($root.'/.env'));
        $this->assertSame('local runtime', file_get_contents($root.'/storage/app/runtime.txt'));
        $this->assertSame('linked upload', file_get_contents($root.'/public/storage/upload.txt'));
        $this->assertSame('new service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertSame('new public index', file_get_contents($root.'/public/index.php'));
        $this->assertFileDoesNotExist($root.'/public/old.txt');
        $this->assertSame([
            'down',
            'migrate --force',
            'optimize:clear',
            'storage:link --force',
            'up',
        ], $commands);
        $this->assertDatabaseHas('system_update_runs', [
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'completed',
            'package_sha256' => hash_file('sha256', $packagePath),
        ]);
    }

    public function test_install_ignores_client_download_url_and_uses_trusted_release_asset(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('trusted-download');
        $trustedPackagePath = $this->fixturePath('trusted-release.tar.gz');
        $trustedDownloadUrl = 'https://example.test/releases/gx-om-backend-v1.2.4.tar.gz';
        $maliciousDownloadUrl = 'https://evil.test/gx-om-backend-v1.2.4.tar.gz';

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->writeGzipTar($trustedPackagePath, [
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
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => $trustedDownloadUrl],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => hash_file('sha256', $trustedPackagePath),
            ], JSON_THROW_ON_ERROR)),
            'example.test/pkg.tar.gz.sha256' => Http::response(hash_file('sha256', $trustedPackagePath).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            $trustedDownloadUrl => Http::response(file_get_contents($trustedPackagePath)),
            'evil.test/*' => Http::response('this should not be fetched'),
        ]);

        $this->bindInstallerForRoot($root);
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $trustedPackagePath),
            'download_url' => $maliciousDownloadUrl,
            'confirmed' => true,
        ]);

        $response->assertAccepted();
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($trustedDownloadUrl): bool {
            return $request->url() === $trustedDownloadUrl;
        });
        Http::assertSentCount(4);
        $this->assertSame('new service', file_get_contents($root.'/app/Services/Existing.php'));
        $this->assertDatabaseHas('system_update_runs', [
            'actor_user_id' => $admin->id,
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'completed',
            'package_sha256' => hash_file('sha256', $trustedPackagePath),
        ]);
    }

    public function test_install_uses_in_process_artisan_calls_instead_of_configured_php_binary(): void
    {
        Storage::fake();
        $root = $this->fixtureDeploymentRoot('configured-php-binary');
        $packagePath = $this->fixturePath('configured-php-binary-release.tar.gz');
        $fakePhpLogPath = $this->fixturePath('configured-php-binary.log');
        $fakePhpPath = $this->writeFakePhpBinary($fakePhpLogPath);

        if (is_file($fakePhpLogPath)) {
            unlink($fakePhpLogPath);
        }

        config()->set('system_update.php_binary', $fakePhpPath);

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'artisan' => "<?php fwrite(STDERR, 'real php binary should not be used'); exit(42);\n",
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
            'artisan' => '<?php',
            'composer.lock' => '{}',
            'release.json' => '{"version":"1.2.4","tag":"v1.2.4"}',
        ]);

        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => hash_file('sha256', $packagePath),
            ], JSON_THROW_ON_ERROR)),
            'example.test/pkg.tar.gz.sha256' => Http::response(hash_file('sha256', $packagePath).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
            'example.test/gx-om-backend-v1.2.4.tar.gz' => Http::response(file_get_contents($packagePath)),
        ]);

        $this->bindInstallerForRootUsingRealCommands($root);
        $this->actingAsAdmin();

        Artisan::shouldReceive('call')->once()->with('down')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('migrate --force')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('optimize:clear')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('storage:link --force')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('up')->andReturn(0);

        $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => hash_file('sha256', $packagePath),
            'confirmed' => true,
        ])->assertAccepted();

        $this->assertFileDoesNotExist($fakePhpLogPath);
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
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.4',
                'draft' => false,
                'prerelease' => false,
                'assets' => [
                    ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/gx-om-backend-v1.2.4.tar.gz'],
                    ['name' => 'gx-om-backend-v1.2.4.tar.gz.sha256', 'browser_download_url' => 'https://example.test/pkg.tar.gz.sha256'],
                ],
            ]),
            'example.test/release-manifest.json' => Http::response(json_encode([
                'version' => '1.2.4',
                'sha256' => hash_file('sha256', $packagePath),
            ], JSON_THROW_ON_ERROR)),
            'example.test/pkg.tar.gz.sha256' => Http::response(hash_file('sha256', $packagePath).'  gx-om-backend-v1.2.4.tar.gz'.PHP_EOL),
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

    private function bindInstallerForRootUsingRealCommands(string $root): void
    {
        $this->app->bind(InPlaceReleaseInstaller::class, fn ($app): InPlaceReleaseInstaller => new InPlaceReleaseInstaller(
            $app->make(ReleasePackageVerifier::class),
            $root,
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

    private function writeFakePhpBinary(string $logPath): string
    {
        $path = $this->fixturePath('fake-php'.(DIRECTORY_SEPARATOR === '\\' ? '.bat' : ''));

        if (DIRECTORY_SEPARATOR === '\\') {
            file_put_contents($path, "@echo off\r\necho %*>>\"{$logPath}\"\r\nexit /b 0\r\n");

            return $path;
        }

        file_put_contents($path, "#!/usr/bin/env sh\nprintf '%s\n' \"$*\" >> ".escapeshellarg($logPath)."\nexit 0\n");
        chmod($path, 0755);

        return $path;
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

    private function writeValidReleasePackage(string $path): string
    {
        $this->writeGzipTar($path, [
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

        return hash_file('sha256', $path);
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
