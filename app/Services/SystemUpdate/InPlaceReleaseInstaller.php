<?php

namespace App\Services\SystemUpdate;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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
        'public/.user.ini',
    ];

    private const PRESERVED_PUBLIC_ENTRIES = [
        'storage',
        '.user.ini',
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
    public function installFromPackage(string $tag, string $packagePath, string $sha256, ?callable $progressReporter = null): array
    {
        $this->verifier->assertValidTag($tag);

        if (! is_file($packagePath)) {
            throw new RuntimeException('Release package path is missing.');
        }

        $workspace = $this->workspace($tag);
        $this->ensureWorkspaceDirectories($workspace);

        return $this->installPreparedPackage($tag, $packagePath, $sha256, $workspace, $progressReporter);
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

    /**
     * @param  array{downloads: string, staging: string, backups: string, runs: string}  $workspace
     * @return array<string, mixed>
     */
    private function installPreparedPackage(
        string $tag,
        string $packagePath,
        string $sha256,
        array $workspace,
        ?callable $progressReporter = null
    ): array {
        $root = $this->root();
        $stagingPath = $workspace['staging'].DIRECTORY_SEPARATOR.$tag.'-'.uniqid('', true);
        $backupPath = $workspace['backups'].DIRECTORY_SEPARATOR.$tag.'-'.date('YmdHis').'-'.uniqid('', true);

        $this->reportProgress($progressReporter, 'verifying', 'Verifying release package.');
        $this->verifier->assertSha256($packagePath, $sha256);
        $this->verifier->assertSafeArchive($packagePath);

        try {
            $this->reportProgress($progressReporter, 'extracting', 'Extracting release package.');
            $this->extractArchive($packagePath, $stagingPath);
            $this->reportProgress($progressReporter, 'backing_up', 'Backing up managed application files.');
            $this->backupManagedEntries($root, $backupPath);
            $this->reportProgress($progressReporter, 'maintenance_down', 'Putting application into maintenance mode.');
            $this->runArtisanCommand('down', $root);
            $this->reportProgress($progressReporter, 'replacing', 'Replacing managed application files.');
            $this->replaceManagedEntries($root, $stagingPath);
            $this->reportProgress($progressReporter, 'migrating', 'Running database migrations.');
            $this->runArtisanCommand('migrate --force', $root);
            $this->reportProgress($progressReporter, 'clearing_cache', 'Clearing application caches.');
            $this->runArtisanCommand('optimize:clear', $root);
            $this->reportProgress($progressReporter, 'linking_storage', 'Refreshing storage link.');
            $this->runArtisanCommand('storage:link --force', $root);
            $this->reportProgress($progressReporter, 'maintenance_up', 'Bringing application back online.');
            $this->runArtisanCommand('up', $root);
            $this->reportProgress($progressReporter, 'pruning_backups', 'Pruning old update backups.');
            $this->pruneBackups($workspace['backups']);
        } catch (Throwable $throwable) {
            $this->reportProgress($progressReporter, 'rolling_back', 'Install failed; restoring backup.');
            $rollbackFailure = null;

            try {
                $this->restoreBackup($root, $backupPath);
            } catch (Throwable $rollbackThrowable) {
                $rollbackFailure = $rollbackThrowable;
            }

            $this->tryBringApplicationUp($root);

            if ($rollbackFailure !== null) {
                throw new RuntimeException(
                    $throwable->getMessage().'; rollback restore failed: '.$rollbackFailure->getMessage(),
                    0,
                    $throwable
                );
            }

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
     * @param  array{downloads: string, staging: string, backups: string, runs: string}  $workspace
     */
    private function ensureWorkspaceDirectories(array $workspace): void
    {
        $this->ensureDirectory($workspace['downloads']);
        $this->ensureDirectory($workspace['staging']);
        $this->ensureDirectory($workspace['backups']);
        $this->ensureDirectory($workspace['runs']);
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
                $this->copyDirectoryContents($source, $this->join($backupPath, $entry), self::PRESERVED_PUBLIC_ENTRIES);

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
                $this->replaceDirectoryContents($source, $target, self::PRESERVED_PUBLIC_ENTRIES);

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
                $this->replaceDirectoryContents($source, $target, self::PRESERVED_PUBLIC_ENTRIES);

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

        $exitCode = Artisan::call($command);

        if ($exitCode !== 0) {
            throw new RuntimeException("Artisan command failed [{$command}]: ".Artisan::output());
        }
    }

    private function reportProgress(?callable $progressReporter, string $step, string $line): void
    {
        if ($progressReporter !== null) {
            $progressReporter($step, $line);
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
