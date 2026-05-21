<?php

declare(strict_types=1);

$optionNames = [
    'apk',
    'expected-version-code',
    'expected-version-name',
    'expected-certificate-sha256',
];
$options = getopt('', [
    'apk:',
    'expected-version-code:',
    'expected-version-name:',
    'expected-certificate-sha256:',
]);
$options = array_merge($options, parseLongOptions($argv, $optionNames));

$manifestPath = resolveManifestPath($argv, $optionNames);

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Manifest not found: {$manifestPath}\n");
    exit(1);
}

$json = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($json)) {
    fwrite(STDERR, "Manifest is not valid JSON\n");
    exit(1);
}

$required = [
    'versionCode',
    'versionName',
    'url',
    'sha256',
    'packageName',
    'certificateSha256',
    'channel',
    'force',
    'description',
];

foreach ($required as $field) {
    if (! array_key_exists($field, $json)) {
        fwrite(STDERR, "Missing required field: {$field}\n");
        exit(1);
    }
}

$errors = [];

if (! is_int($json['versionCode']) || $json['versionCode'] < 1) {
    $errors[] = 'versionCode must be a positive integer';
}

if (! is_string($json['versionName']) || trim($json['versionName']) === '') {
    $errors[] = 'versionName must be a non-empty string';
}

if (! is_string($json['url']) || ! isTrustedAppUpdateUrl($json['url'])) {
    $errors[] = 'url must use the trusted app_update HTTPS host';
}

$manifestSha256IsValid = is_string($json['sha256']) && isLowercaseSha256($json['sha256']);
if (! $manifestSha256IsValid) {
    $errors[] = 'sha256 must be 64 lowercase hex characters';
}

if ($json['packageName'] !== 'com.java.gx_om') {
    $errors[] = 'packageName must be com.java.gx_om';
}

if (
    ! is_string($json['certificateSha256']) ||
    ! isLowercaseSha256(normalizeFingerprint($json['certificateSha256']))
) {
    $errors[] = 'certificateSha256 must be a SHA-256 fingerprint';
}

if ($json['channel'] !== 'production') {
    $errors[] = 'channel must be production';
}

if (! is_bool($json['force'])) {
    $errors[] = 'force must be boolean';
}

if (! is_string($json['description'])) {
    $errors[] = 'description must be a string';
}

if ($json['versionCode'] === 100 && $json['versionName'] === '1.0.0' && $json['force'] === true) {
    $errors[] = 'manifest must not advertise the old forced phantom version';
}

if (isset($options['apk'])) {
    $apkPath = (string) $options['apk'];
    if (! is_file($apkPath)) {
        $errors[] = "APK not found: {$apkPath}";
    } elseif ($manifestSha256IsValid) {
        $apkSha256 = hash_file('sha256', $apkPath);
        if ($apkSha256 === false || strtolower($apkSha256) !== $json['sha256']) {
            $errors[] = 'sha256 does not match APK';
        }
    }
}

if (isset($options['expected-version-code'])) {
    $expectedVersionCode = filter_var($options['expected-version-code'], FILTER_VALIDATE_INT);
    if ($expectedVersionCode === false || $json['versionCode'] !== $expectedVersionCode) {
        $errors[] = 'versionCode does not match expected value';
    }
}

if (isset($options['expected-version-name']) && $json['versionName'] !== (string) $options['expected-version-name']) {
    $errors[] = 'versionName does not match expected value';
}

if (isset($options['expected-certificate-sha256'])) {
    $expectedCertificate = normalizeFingerprint((string) $options['expected-certificate-sha256']);
    if (normalizeFingerprint((string) $json['certificateSha256']) !== $expectedCertificate) {
        $errors[] = 'certificateSha256 does not match expected value';
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "{$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Manifest valid: {$manifestPath}\n");

function normalizeFingerprint(string $value): string
{
    return strtolower(str_replace(':', '', trim($value)));
}

/**
 * getopt() ignores long options after the first positional argument.
 */
function parseLongOptions(array $argv, array $optionNames): array
{
    $options = [];
    $optionLookup = array_fill_keys($optionNames, true);

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $argument = $argv[$i];
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        $option = substr($argument, 2);
        $value = null;
        if (str_contains($option, '=')) {
            [$option, $value] = explode('=', $option, 2);
        } elseif (($optionLookup[$option] ?? false) && isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '-')) {
            $value = $argv[++$i];
        }

        if (($optionLookup[$option] ?? false) && $value !== null) {
            $options[$option] = $value;
        }
    }

    return $options;
}

function resolveManifestPath(array $argv, array $optionNames): string
{
    $manifestPaths = [];

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $argument = $argv[$i];
        if (str_starts_with($argument, '--')) {
            if (optionRequiresValue($argument, $optionNames) && isset($argv[$i + 1])) {
                $i++;
            }
            continue;
        }

        $manifestPaths[] = $argument;
    }

    if (count($manifestPaths) > 1) {
        fwrite(STDERR, 'Multiple manifest paths provided: ' . implode(', ', $manifestPaths) . "\n");
        exit(1);
    }

    return $manifestPaths[0] ?? __DIR__ . '/../public/app_update/update.json';
}

function optionRequiresValue(string $argument, array $optionNames): bool
{
    $option = substr($argument, 2);
    if (str_contains($option, '=')) {
        return false;
    }

    return in_array($option, $optionNames, true);
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
