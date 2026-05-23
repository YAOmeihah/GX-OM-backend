<?php

declare(strict_types=1);

$options = getopt('', [
    'source:',
    'output:',
]);

$required = ['source', 'output'];
foreach ($required as $field) {
    if (!isset($options[$field]) || trim((string) $options[$field]) === '') {
        fwrite(STDERR, "Missing --{$field}\n");
        exit(1);
    }
}

$sourceDir = realpath((string) $options['source']);
if ($sourceDir === false || !is_dir($sourceDir)) {
    fwrite(STDERR, "Source directory not found: {$options['source']}\n");
    exit(1);
}

$outputPath = (string) $options['output'];
$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
    exit(1);
}

$archiveTemp = $outputPath . '.tmp.tar';
$archiveGzTemp = $outputPath . '.tmp.tar.gz';
@unlink($archiveTemp);
@unlink($archiveGzTemp);
@unlink($outputPath);

$archive = new PharData($archiveTemp);
$archive->buildFromDirectory($sourceDir);
$archive->compress(Phar::GZ);
unset($archive);

if (!file_exists($archiveGzTemp)) {
    fwrite(STDERR, "Failed to create compressed archive\n");
    exit(1);
}

if (!rename($archiveGzTemp, $outputPath)) {
    fwrite(STDERR, "Failed to move archive into place\n");
    exit(1);
}

@unlink($archiveTemp);

$sha256 = hash_file('sha256', $outputPath);
if ($sha256 === false) {
    fwrite(STDERR, "Failed to hash archive\n");
    exit(1);
}

$shaPath = $outputPath . '.sha256';
file_put_contents($shaPath, $sha256 . '  ' . basename($outputPath) . "\n");

fwrite(STDOUT, "Archive written: {$outputPath}\n");
fwrite(STDOUT, "Checksum written: {$shaPath}\n");
