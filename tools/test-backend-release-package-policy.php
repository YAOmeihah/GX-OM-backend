<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$preparer = $root . '/tools/prepare-backend-release.php';
$builder = $root . '/tools/build-backend-release.php';
$tmp = sys_get_temp_dir() . '/gx-om-backend-release-package-policy-' . bin2hex(random_bytes(4));
$source = $tmp . '/source';
$release = $tmp . '/release';
$assets = $tmp . '/assets';
$archive = $assets . '/fixture.tar.gz';

mkdir($source, 0777, true);
mkdir($assets, 0777, true);

$requiredEntries = [
    'app/',
    'bootstrap/',
    'config/',
    'database/',
    'public/',
    'public/build/',
    'resources/',
    'routes/',
    'vendor/',
    'artisan',
    'composer.lock',
    'release.json',
];

$blockedEntries = [
    '.scribe/',
    '.editorconfig',
    '.gitattributes',
    '.gitignore',
    '.github/',
    'API_DOCUMENTATION.md',
    'PERMISSION_SYSTEM.md',
    'composer.json',
    'create_test_data.php',
    'generate_test_token.php',
    'node_modules/',
    'package-lock.json',
    'package.json',
    'phpstan.neon',
    'phpunit.xml',
    'postcss.config.js',
    'storage/',
    'tailwind.config.js',
    'test-permissions.php',
    'tests/',
    'tools/',
    'vite.config.js',
];

try {
    foreach ($requiredEntries as $entry) {
        if ($entry === 'release.json') {
            continue;
        }

        createFixtureEntry($source, $entry);
    }

    foreach ($blockedEntries as $entry) {
        createFixtureEntry($source, $entry);
    }

    assertCommandSucceeds(
        'php ' . escapeshellarg($preparer)
        . ' --source=' . escapeshellarg($source)
        . ' --output=' . escapeshellarg($release)
    );

    file_put_contents($release . '/release.json', '{"version":"fixture"}');

    assertCommandSucceeds(
        'php ' . escapeshellarg($builder)
        . ' --source=' . escapeshellarg($release)
        . ' --output=' . escapeshellarg($archive)
    );

    $files = archiveFiles($archive);

    foreach ($requiredEntries as $entry) {
        assertArchiveContains($files, $entry);
    }

    foreach ($blockedEntries as $entry) {
        assertArchiveDoesNotContain($files, $entry);
    }

    fwrite(STDOUT, "Backend release package policy tests passed\n");
} finally {
    removeDirectory($tmp);
}

function createFixtureEntry(string $base, string $entry): void
{
    $path = $base . '/' . rtrim($entry, '/');
    if (str_ends_with($entry, '/')) {
        mkdir($path, 0777, true);
        file_put_contents($path . '/.keep', 'fixture');

        return;
    }

    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($path, 'fixture');
}

function archiveFiles(string $archive): array
{
    exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed to list archive:\n" . implode("\n", $output) . "\n");
        exit(1);
    }

    return array_values(array_filter(array_map(static fn (string $line): string => ltrim($line, './'), $output)));
}

function assertArchiveContains(array $files, string $entry): void
{
    $entry = rtrim($entry, '/');
    foreach ($files as $file) {
        if ($file === $entry || str_starts_with($file, $entry . '/')) {
            return;
        }
    }

    fwrite(STDERR, "Archive is missing required entry: {$entry}\n");
    exit(1);
}

function assertArchiveDoesNotContain(array $files, string $entry): void
{
    $entry = rtrim($entry, '/');
    foreach ($files as $file) {
        if ($file === $entry || str_starts_with($file, $entry . '/')) {
            fwrite(STDERR, "Archive contains blocked entry: {$entry}\n");
            exit(1);
        }
    }
}

function assertCommandSucceeds(string $command): void
{
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Expected success, got {$code}:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
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
