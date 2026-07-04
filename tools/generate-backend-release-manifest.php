<?php

declare(strict_types=1);

$options = getopt('', [
    'release-name:',
    'version:',
    'tag:',
    'commit:',
    'build-time:',
    'package-name::',
    'sha256::',
    'notes::',
    'output::',
]);

$required = ['release-name', 'version', 'tag', 'commit', 'build-time'];
foreach ($required as $field) {
    if (! isset($options[$field]) || trim((string) $options[$field]) === '') {
        fwrite(STDERR, "Missing --{$field}\n");
        exit(1);
    }
}

$releaseName = trim((string) $options['release-name']);
$version = trim((string) $options['version']);
$tag = trim((string) $options['tag']);
$commit = trim((string) $options['commit']);
$buildTime = trim((string) $options['build-time']);
$packageName = trim((string) ($options['package-name'] ?? ''));
$sha256 = strtolower(trim((string) ($options['sha256'] ?? '')));
$notes = trim((string) ($options['notes'] ?? ''));

if ($sha256 !== '' && ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
    fwrite(STDERR, "--sha256 must be a lowercase SHA-256 hash\n");
    exit(1);
}

$manifest = [
    'release_name' => $releaseName,
    'version' => $version,
    'tag' => $tag,
    'commit' => $commit,
    'build_time' => $buildTime,
    'notes' => $notes,
];

if ($packageName !== '') {
    $manifest['package_name'] = $packageName;
}

if ($sha256 !== '') {
    $manifest['sha256'] = $sha256;
}

$output = (string) ($options['output'] ?? __DIR__.'/../release-manifest.json');
$outputDir = dirname($output);
if (! is_dir($outputDir)) {
    fwrite(STDERR, "Output directory not found: {$outputDir}\n");
    exit(1);
}

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, "Failed to encode manifest\n");
    exit(1);
}

file_put_contents($output, $encoded."\n");
fwrite(STDOUT, "Manifest written: {$output}\n");
