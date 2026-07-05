<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemUpdateRun;
use App\Models\User;
use App\Services\SystemUpdate\InPlaceReleaseInstaller;
use App\Services\SystemUpdate\ReleasePackageVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemUpdateInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_install_endpoint_is_retired(): void
    {
        $this->actingAsAdmin();
        Http::fake();

        $this->postJson('/api/system-updates/install', [
            'tag' => 'v1.2.4',
            'sha256' => str_repeat('a', 64),
            'confirmed' => true,
        ])
            ->assertStatus(410)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('system_update_runs', 0);
    }

    public function test_upload_package_sha256_mismatch_is_rejected(): void
    {
        $packagePath = $this->fixturePath('sha-mismatch.tar.gz');
        $this->writeValidReleasePackage($packagePath);
        $this->actingAsAdmin();

        $this->post('/api/system-updates/uploads', [
            'tag' => 'v1.2.4',
            'sha256' => str_repeat('0', 64),
            'package' => new UploadedFile(
                $packagePath,
                'gx-om-backend-v1.2.4.tar.gz',
                'application/gzip',
                null,
                true
            ),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Uploaded release package SHA256 mismatch.');

        $this->assertDatabaseCount('system_update_runs', 0);
    }

    public function test_upload_package_creates_uploaded_run_without_installing(): void
    {
        $packagePath = $this->fixturePath('uploaded-release.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);

        $this->mock(InPlaceReleaseInstaller::class, function ($mock): void {
            $mock->shouldReceive('installFromPackage')->never();
        });

        $admin = $this->actingAsAdmin();

        $response = $this->post('/api/system-updates/uploads', [
            'tag' => 'v1.2.4',
            'sha256' => $sha256,
            'package' => new UploadedFile(
                $packagePath,
                'gx-om-backend-v1.2.4.tar.gz',
                'application/gzip',
                null,
                true
            ),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.step', 'uploaded');

        $run = SystemUpdateRun::query()->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $run->actor_user_id);
        $this->assertSame('v1.2.4', $run->tag);
        $this->assertSame('1.2.4', $run->version);
        $this->assertSame('uploaded', $run->status);
        $this->assertSame('uploaded', $run->step);
        $this->assertSame($sha256, $run->package_sha256);
        $this->assertSame('upload', $run->metadata['source']);
        $this->assertSame('gx-om-backend-v1.2.4.tar.gz', $run->metadata['package_name']);
        $this->assertFileExists((string) $run->package_path);
        $this->assertSame($sha256, hash_file('sha256', (string) $run->package_path));
    }

    public function test_queue_uploaded_run_only_marks_it_for_cli_worker(): void
    {
        $packagePath = $this->fixturePath('queue-only.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);
        $this->actingAsAdmin();

        $this->mock(InPlaceReleaseInstaller::class, function ($mock): void {
            $mock->shouldReceive('installFromPackage')->never();
        });

        $run = SystemUpdateRun::query()->create([
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'uploaded',
            'step' => 'uploaded',
            'metadata' => ['source' => 'upload', 'package_name' => 'gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Uploaded release package.'],
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
        ]);

        $this->postJson("/api/system-updates/runs/{$run->id}/queue")
            ->assertAccepted()
            ->assertJsonPath('data.run_id', $run->id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.step', 'queued');

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame('queued', $run->step);
        $this->assertContains('Queued for CLI system update worker.', $run->log_lines);
    }

    public function test_worker_once_installs_a_queued_uploaded_package(): void
    {
        $root = $this->fixtureDeploymentRoot('worker-success');
        $packagePath = $this->fixturePath('worker-success.tar.gz');
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

        $run = $this->createQueuedRun($packagePath, $sha256);

        $exitCode = Artisan::call('system-update:worker', ['--once' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());
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
        $this->assertContains('Verifying release package.', $run->log_lines);
        $this->assertContains('Replacing managed application files.', $run->log_lines);
        $this->assertContains('Uploaded release package install completed.', $run->log_lines);
    }

    public function test_worker_lock_prevents_concurrent_installation(): void
    {
        $packagePath = $this->fixturePath('locked-worker.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);
        $run = $this->createQueuedRun($packagePath, $sha256);
        $lock = Cache::lock('system-update:worker', 600);

        $this->assertTrue($lock->get());

        try {
            $exitCode = Artisan::call('system-update:worker', ['--once' => true]);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $exitCode, Artisan::output());
        $run->refresh();
        $this->assertSame('queued', $run->status);
    }

    public function test_worker_failure_marks_run_failed_with_error_message(): void
    {
        $root = $this->fixtureDeploymentRoot('worker-failure');
        $packagePath = $this->fixturePath('worker-failure.tar.gz');
        $sha256 = $this->writeValidReleasePackage($packagePath);

        $this->writeDeploymentRoot($root, [
            '.env' => 'APP_KEY=existing',
            'storage/app/runtime.txt' => 'local runtime',
            'public/storage/upload.txt' => 'linked upload',
            'app/Services/Existing.php' => 'old service',
            'public/index.php' => 'old public index',
            'release.json' => '{"version":"1.2.3"}',
        ]);

        $this->bindInstallerForRoot($root, function (string $command): void {
            if ($command === 'migrate --force') {
                throw new RuntimeException('Migration failed.');
            }
        });

        $run = $this->createQueuedRun($packagePath, $sha256);

        $exitCode = Artisan::call('system-update:worker', ['--once' => true]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('old service', file_get_contents($root.'/app/Services/Existing.php'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('rolled_back', $run->step);
        $this->assertSame('Migration failed.', $run->error_message);
        $this->assertNotNull($run->finished_at);
        $this->assertContains('Install failed; restoring backup.', $run->log_lines);
    }

    public function test_worker_marks_stale_running_run_failed_before_claiming_work(): void
    {
        config()->set('system_update.stale_run_minutes', 10);

        $run = SystemUpdateRun::query()->create([
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'running',
            'step' => 'verifying',
            'metadata' => ['source' => 'upload', 'package_name' => 'gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Started uploaded release package install.', 'Verifying release package.'],
            'package_path' => $this->fixturePath('stale-running.tar.gz'),
            'package_sha256' => str_repeat('a', 64),
            'started_at' => now()->subMinutes(15),
        ]);
        $run->timestamps = false;
        $run->updated_at = now()->subMinutes(15);
        $run->save();

        $exitCode = Artisan::call('system-update:worker', ['--once' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $run->step);
        $this->assertSame('System update worker stopped before completing this run.', $run->error_message);
        $this->assertNotNull($run->finished_at);
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

    private function createQueuedRun(string $packagePath, string $sha256): SystemUpdateRun
    {
        return SystemUpdateRun::query()->create([
            'tag' => 'v1.2.4',
            'version' => '1.2.4',
            'status' => 'queued',
            'step' => 'queued',
            'metadata' => ['source' => 'upload', 'package_name' => 'gx-om-backend-v1.2.4.tar.gz'],
            'log_lines' => ['Uploaded release package.', 'Queued for CLI system update worker.'],
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
        ]);
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
