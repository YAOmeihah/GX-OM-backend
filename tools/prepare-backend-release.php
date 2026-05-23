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
    'app',
    'bootstrap',
    'config',
    'database',
    'public',
    'resources',
    'routes',
    'vendor',
    'artisan',
    'composer.lock',
];

foreach ($runtimeEntries as $entry) {
    $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $entry;
    if (! file_exists($sourcePath)) {
        fwrite(STDERR, "Missing runtime entry: {$entry}\n");
        exit(1);
    }

    $targetPath = $outputDir . DIRECTORY_SEPARATOR . $entry;
    is_dir($sourcePath) ? copyDirectory($sourcePath, $targetPath) : copyFile($sourcePath, $targetPath);
}

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
        $targetPath = $target . DIRECTORY_SEPARATOR . $items->getSubPathName();
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
