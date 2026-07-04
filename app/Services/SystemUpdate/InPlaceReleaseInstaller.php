<?php

namespace App\Services\SystemUpdate;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class InPlaceReleaseInstaller
{
    private const MANAGED_ENTRIES = [
        '.env.example',
        'app',
        'artisan',
        'bootstrap',
        'composer.lock',
        'config',
        'database',
        'public',
        'release.json',
        'resources',
        'routes',
        'vendor',
    ];

    private const PRESERVED_ENTRIES = [
        '.env',
        'storage',
        'public/storage',
    ];

    private Filesystem $files;

    /**
     * @param  callable(string): void|null  $commandRunner
     */
    public function __construct(
        private readonly ReleasePackageVerifier $verifier,
        private readonly ?string $deploymentRoot = null,
        private readonly mixed $commandRunner = null,
    ) {
        $this->files = new Filesystem;
    }

    /**
     * @return array<string, mixed>
     */
    public function install(string $tag, string $downloadUrl, string $sha256): array
    {
        $this->verifier->assertValidTag($tag);

        $root = $this->root();
        $workspace = $this->workspace($tag);
        $packagePath = $workspace['downloads'].DIRECTORY_SEPARATOR."gx-om-backend-{$tag}.tar.gz";
        $stagingPath = $workspace['staging'].DIRECTORY_SEPARATOR.$tag.'-'.uniqid('', true);
        $backupPath = $workspace['backups'].DIRECTORY_SEPARATOR.$tag.'-'.date('YmdHis').'-'.uniqid('', true);

        $this->ensureDirectory($workspace['downloads']);
        $this->ensureDirectory($workspace['staging']);
        $this->ensureDirectory($workspace['backups']);
        $this->ensureDirectory($workspace['runs']);

        $this->downloadPackage($downloadUrl, $packagePath);
        $this->verifier->assertSha256($packagePath, $sha256);
        $this->verifier->assertSafeArchive($packagePath);

        try {
            $this->extractArchive($packagePath, $stagingPath);
            $this->backupManagedEntries($root, $backupPath);
            $this->runArtisanCommand('down', $root);
            $this->replaceManagedEntries($root, $stagingPath);
            $this->runArtisanCommand('migrate --force', $root);
            $this->runArtisanCommand('optimize:clear', $root);
            $this->runArtisanCommand('storage:link --force', $root);
            $this->runArtisanCommand('up', $root);
            $this->pruneBackups($workspace['backups']);
        } catch (Throwable $throwable) {
            $this->restoreBackup($root, $backupPath);
            $this->tryBringApplicationUp($root);

            throw $throwable;
        } finally {
            if (is_dir($stagingPath)) {
                $this->files->deleteDirectory($stagingPath);
            }
        }

        return [
            'tag' => $tag,
            'package_path' => $packagePath,
            'backup_path' => $backupPath,
            'sha256' => strtolower($sha256),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(string $backupPath): array
    {
        $root = $this->root();

        if (! is_dir($backupPath)) {
            throw new RuntimeException('Rollback backup path is missing.');
        }

        $this->restoreBackup($root, $backupPath);
        $this->tryBringApplicationUp($root);

        return [
            'backup_path' => $backupPath,
        ];
    }

    private function root(): string
    {
        return rtrim($this->deploymentRoot ?? base_path(), DIRECTORY_SEPARATOR.'/\\');
    }

    /**
     * @return array{downloads: string, staging: string, backups: string, runs: string}
     */
    private function workspace(string $tag): array
    {
        $base = $this->root().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'system_updates';

        return [
            'downloads' => $base.DIRECTORY_SEPARATOR.'downloads',
            'staging' => $base.DIRECTORY_SEPARATOR.'staging',
            'backups' => $base.DIRECTORY_SEPARATOR.'backups',
            'runs' => $base.DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$tag.'-'.date('YmdHis'),
        ];
    }

    private function downloadPackage(string $downloadUrl, string $packagePath): void
    {
        $request = Http::timeout(120)->retry(3, 1000);
        $token = trim((string) config('system_update.github.token', ''));

        if (str_starts_with($downloadUrl, 'https://api.github.com/')) {
            $request = $request->withHeaders([
                'Accept' => 'application/octet-stream',
            ])->withOptions([
                'version' => 1.1,
            ]);

            if ($token !== '') {
                $request = $request->withToken($token);
            }
        }

        $response = $request->get($downloadUrl)->throw();

        file_put_contents($packagePath, $response->body());
    }

    private function extractArchive(string $packagePath, string $stagingPath): void
    {
        $this->ensureDirectory($stagingPath);
        $archive = new \PharData($packagePath);
        $tarPath = substr($packagePath, 0, -3);

        if (is_file($tarPath)) {
            unlink($tarPath);
        }

        try {
            $archive->decompress();
            (new \PharData($tarPath))->extractTo($stagingPath, null, true);
        } finally {
            if (is_file($tarPath)) {
                unlink($tarPath);
            }
        }
    }

    private function backupManagedEntries(string $root, string $backupPath): void
    {
        $this->ensureDirectory($backupPath);

        foreach (self::MANAGED_ENTRIES as $entry) {
            $source = $this->join($root, $entry);

            if (! file_exists($source)) {
                continue;
            }

            if ($entry === 'public') {
                $this->copyDirectoryContents($source, $this->join($backupPath, $entry), ['storage']);

                continue;
            }

            $this->copy($source, $this->join($backupPath, $entry));
        }
    }

    private function replaceManagedEntries(string $root, string $stagingPath): void
    {
        foreach (self::MANAGED_ENTRIES as $entry) {
            if ($this->isPreservedEntry($entry)) {
                continue;
            }

            $target = $this->join($root, $entry);
            $source = $this->join($stagingPath, $entry);

            if ($entry === 'public') {
                $this->replaceDirectoryContents($source, $target, ['storage']);

                continue;
            }

            if (file_exists($target)) {
                $this->remove($target);
            }

            if (file_exists($source)) {
                $this->copy($source, $target);
            }
        }
    }

    private function restoreBackup(string $root, string $backupPath): void
    {
        if (! is_dir($backupPath)) {
            return;
        }

        foreach (self::MANAGED_ENTRIES as $entry) {
            if ($this->isPreservedEntry($entry)) {
                continue;
            }

            $target = $this->join($root, $entry);
            $source = $this->join($backupPath, $entry);

            if ($entry === 'public') {
                $this->replaceDirectoryContents($source, $target, ['storage']);

                continue;
            }

            if (file_exists($target)) {
                $this->remove($target);
            }

            if (file_exists($source)) {
                $this->copy($source, $target);
            }
        }
    }

    private function runArtisanCommand(string $command, string $root): void
    {
        if (is_callable($this->commandRunner)) {
            ($this->commandRunner)($command);

            return;
        }

        $configuredBinary = trim((string) config('system_update.php_binary', ''));
        $binary = $configuredBinary !== '' ? $configuredBinary : PHP_BINARY;
        $artisan = $root.DIRECTORY_SEPARATOR.'artisan';
        $fullCommand = escapeshellarg($binary).' '.escapeshellarg($artisan).' '.$command.' 2>&1';
        exec($fullCommand, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException("Artisan command failed [{$command}]: ".implode("\n", $output));
        }
    }

    private function tryBringApplicationUp(string $root): void
    {
        try {
            $this->runArtisanCommand('up', $root);
        } catch (Throwable) {
            // Keep the original failure visible to the caller.
        }
    }

    private function pruneBackups(string $backupsPath): void
    {
        $limit = (int) config('system_update.backup_limit', 3);

        if ($limit < 1 || ! is_dir($backupsPath)) {
            return;
        }

        $backups = collect($this->files->directories($backupsPath))
            ->sortByDesc(fn (string $path): int|false => filemtime($path))
            ->values();

        $backups->slice($limit)->each(fn (string $path): bool => $this->files->deleteDirectory($path));
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function copy(string $source, string $target): void
    {
        if (is_dir($source)) {
            $this->copyDirectoryContents($source, $target);

            return;
        }

        $this->ensureDirectory(dirname($target));
        copy($source, $target);
    }

    /**
     * @param  list<string>  $preservedNames
     */
    private function replaceDirectoryContents(string $source, string $target, array $preservedNames = []): void
    {
        $this->ensureDirectory($target);

        foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $item) {
            if (in_array($item, $preservedNames, true)) {
                continue;
            }

            $this->remove($target.DIRECTORY_SEPARATOR.$item);
        }

        $this->copyDirectoryContents($source, $target, $preservedNames);
    }

    /**
     * @param  list<string>  $excludedNames
     */
    private function copyDirectoryContents(string $source, string $target, array $excludedNames = []): void
    {
        if (! is_dir($source)) {
            return;
        }

        $this->ensureDirectory($target);

        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
            if (in_array($item, $excludedNames, true)) {
                continue;
            }

            $this->copy($source.DIRECTORY_SEPARATOR.$item, $target.DIRECTORY_SEPARATOR.$item);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            $this->files->deleteDirectory($path);

            return;
        }

        unlink($path);
    }

    private function join(string $root, string $path): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function isPreservedEntry(string $entry): bool
    {
        return in_array($entry, self::PRESERVED_ENTRIES, true);
    }
}
