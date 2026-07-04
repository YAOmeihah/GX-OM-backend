<?php

declare(strict_types=1);

$options = getopt('', [
    'apk:',
    'version-code:',
    'version-name:',
    'url:',
    'certificate-sha256:',
    'channel::',
    'force::',
    'description::',
    'output::',
]);

$required = ['apk', 'version-code', 'version-name', 'url', 'certificate-sha256'];
foreach ($required as $field) {
    if (! isset($options[$field]) || trim((string) $options[$field]) === '') {
        fwrite(STDERR, "Missing --{$field}\n");
        exit(1);
    }
}

$apkPath = (string) $options['apk'];
if (! is_file($apkPath)) {
    fwrite(STDERR, "APK not found: {$apkPath}\n");
    exit(1);
}

$versionCode = filter_var($options['version-code'], FILTER_VALIDATE_INT);
if ($versionCode === false || $versionCode < 1) {
    fwrite(STDERR, "--version-code must be a positive integer\n");
    exit(1);
}

$versionName = trim((string) $options['version-name']);
if ($versionName === '') {
    fwrite(STDERR, "--version-name must be a non-empty string\n");
    exit(1);
}

$url = (string) $options['url'];
if (! isTrustedAppUpdateUrl($url)) {
    fwrite(STDERR, "--url must use https://api-gx-om.hrlni.cn/app_update/\n");
    exit(1);
}

$certificateSha256 = normalizeFingerprint((string) $options['certificate-sha256']);
if (! isLowercaseSha256($certificateSha256)) {
    fwrite(STDERR, "--certificate-sha256 must be a SHA-256 fingerprint\n");
    exit(1);
}

$channel = (string) ($options['channel'] ?? 'production');
if ($channel !== 'production') {
    fwrite(STDERR, "--channel must be production\n");
    exit(1);
}

$force = filter_var($options['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($force === null) {
    fwrite(STDERR, "--force must be boolean\n");
    exit(1);
}

$apkSha256 = hash_file('sha256', $apkPath);
if ($apkSha256 === false) {
    fwrite(STDERR, "Failed to hash APK: {$apkPath}\n");
    exit(1);
}

$manifest = [
    'versionCode' => $versionCode,
    'versionName' => $versionName,
    'url' => $url,
    'sha256' => strtolower($apkSha256),
    'packageName' => 'com.java.gx_om',
    'certificateSha256' => $certificateSha256,
    'channel' => $channel,
    'force' => $force,
    'description' => (string) ($options['description'] ?? ''),
];

$output = (string) ($options['output'] ?? __DIR__.'/../public/app_update/update.json');
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

function normalizeFingerprint(string $value): string
{
    return strtolower(str_replace(':', '', trim($value)));
}

function isLowercaseSha256(string $value): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function isTrustedAppUpdateUrl(string $url): bool
{
    $parts = parse_url($url);

    return is_array($parts)
        && ($parts['scheme'] ?? null) === 'https'
        && ($parts['host'] ?? null) === 'api-gx-om.hrlni.cn'
        && str_starts_with($parts['path'] ?? '', '/app_update/');
}
