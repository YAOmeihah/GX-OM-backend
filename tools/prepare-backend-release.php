<?php

declare(strict_types=1);

$options = getopt('', [
    'source:',
    'output:',
]);

$required = ['source', 'output'];
foreach ($required as $field) {
    if (! isset($options[$field]) || trim((string) $options[$field]) === '') {
        fwrite(STDERR, "Missing --{$field}\n");
        exit(1);
    }
}

$sourceDir = realpath((string) $options['source']);
if ($sourceDir === false || ! is_dir($sourceDir)) {
    fwrite(STDERR, "Source directory not found: {$options['source']}\n");
    exit(1);
}

$outputDir = (string) $options['output'];
if (file_exists($outputDir)) {
    removeDirectory($outputDir);
}

if (! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
    exit(1);
}

$runtimeEntries = [
    '.env.example',
    'app',
    'bootstrap',
    'config',
    'database',
    'public',
    'resources',
    'routes',
    'scripts/update-backend.sh',
    'vendor',
    'artisan',
    'composer.lock',
];

foreach ($runtimeEntries as $entry) {
    $sourcePath = $sourceDir.DIRECTORY_SEPARATOR.$entry;
    if (! file_exists($sourcePath)) {
        fwrite(STDERR, "Missing runtime entry: {$entry}\n");
        exit(1);
    }

    $targetPath = $outputDir.DIRECTORY_SEPARATOR.$entry;
    is_dir($sourcePath) ? copyDirectory($sourcePath, $targetPath) : copyFile($sourcePath, $targetPath);
}

$storageDirectories = [
    'storage/app/maintenance_exports',
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

foreach ($storageDirectories as $directory) {
    $targetPath = $outputDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (! is_dir($targetPath) && ! mkdir($targetPath, 0777, true) && ! is_dir($targetPath)) {
        fwrite(STDERR, "Failed to create storage directory: {$directory}\n");
        exit(1);
    }

    file_put_contents($targetPath.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n");
}

$publicStorageLink = $outputDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
if (is_link($publicStorageLink) || file_exists($publicStorageLink)) {
    unlink($publicStorageLink);
}

if (! is_dir(dirname($publicStorageLink)) && ! mkdir(dirname($publicStorageLink), 0777, true) && ! is_dir(dirname($publicStorageLink))) {
    fwrite(STDERR, "Failed to create public storage link parent\n");
    exit(1);
}

if (! mkdir($publicStorageLink, 0777, true) && ! is_dir($publicStorageLink)) {
    fwrite(STDERR, "Failed to create public storage directory\n");
    exit(1);
}

file_put_contents($publicStorageLink.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n");

fwrite(STDOUT, "Release directory prepared: {$outputDir}\n");

function copyDirectory(string $source, string $target): void
{
    if (! is_dir($target) && ! mkdir($target, 0777, true) && ! is_dir($target)) {
        fwrite(STDERR, "Failed to create directory: {$target}\n");
        exit(1);
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $targetPath = $target.DIRECTORY_SEPARATOR.$items->getSubPathName();
        if ($item->isDir()) {
            if (! is_dir($targetPath) && ! mkdir($targetPath, 0777, true) && ! is_dir($targetPath)) {
                fwrite(STDERR, "Failed to create directory: {$targetPath}\n");
                exit(1);
            }

            continue;
        }

        copyFile($item->getPathname(), $targetPath);
    }
}

function copyFile(string $source, string $target): void
{
    $targetDir = dirname($target);
    if (! is_dir($targetDir) && ! mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
        fwrite(STDERR, "Failed to create directory: {$targetDir}\n");
        exit(1);
    }

    if (! copy($source, $target)) {
        fwrite(STDERR, "Failed to copy {$source} to {$target}\n");
        exit(1);
    }
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        unlink($dir);

        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}
