<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$generator = $root . '/tools/generate-backend-release-manifest.php';
$tmp = sys_get_temp_dir() . '/gx-om-backend-release-manifest-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

try {
    $manifest = $tmp . '/release-manifest.json';
    $validHash = str_repeat('a', 64);

    assertCommandSucceeds(
        "php " . escapeshellarg($generator)
        . " --release-name=" . escapeshellarg('GX-OM Backend')
        . " --version=1.2.3"
        . " --tag=v1.2.3"
        . " --commit=abcdef123456"
        . " --build-time=2026-05-23T00:00:00Z"
        . " --package-name=gx-om-backend-v1.2.3.tar.gz"
        . " --sha256={$validHash}"
        . " --notes=" . escapeshellarg('fixture notes')
        . " --output=" . escapeshellarg($manifest)
    );

    $json = json_decode((string) file_get_contents($manifest), true);
    if (! is_array($json)) {
        fwrite(STDERR, "Generated manifest is not JSON\n");
        exit(1);
    }

    assertSame('GX-OM Backend', $json['release_name'] ?? null, 'release_name');
    assertSame('1.2.3', $json['version'] ?? null, 'version');
    assertSame('v1.2.3', $json['tag'] ?? null, 'tag');
    assertSame('abcdef123456', $json['commit'] ?? null, 'commit');
    assertSame('2026-05-23T00:00:00Z', $json['build_time'] ?? null, 'build_time');
    assertSame('gx-om-backend-v1.2.3.tar.gz', $json['package_name'] ?? null, 'package_name');
    assertSame($validHash, $json['sha256'] ?? null, 'sha256');
    assertSame('fixture notes', $json['notes'] ?? null, 'notes');

    assertCommandSucceeds(
        "php " . escapeshellarg($generator)
        . " --release-name=" . escapeshellarg('GX-OM Backend')
        . " --version=1.2.3"
        . " --tag=v1.2.3"
        . " --commit=abcdef123456"
        . " --build-time=2026-05-23T00:00:00Z"
        . " --output=" . escapeshellarg($manifest)
    );

    $json = json_decode((string) file_get_contents($manifest), true);
    if (array_key_exists('package_name', $json) || array_key_exists('sha256', $json)) {
        fwrite(STDERR, "Package metadata should be optional for package-internal manifests\n");
        exit(1);
    }

    assertCommandFails(
        "php " . escapeshellarg($generator)
        . " --release-name=" . escapeshellarg('GX-OM Backend')
        . " --version=1.2.3"
        . " --tag=v1.2.3"
        . " --commit=abcdef123456"
        . " --build-time=2026-05-23T00:00:00Z"
        . " --sha256=not-a-hash"
        . " --output=" . escapeshellarg($manifest),
        '--sha256 must be a lowercase SHA-256 hash'
    );

    assertCommandFails(
        "php " . escapeshellarg($generator)
        . " --release-name=" . escapeshellarg('GX-OM Backend')
        . " --version=1.2.3"
        . " --tag=v1.2.3"
        . " --commit=abcdef123456"
        . " --output=" . escapeshellarg($manifest),
        'Missing --build-time'
    );

    fwrite(STDOUT, "Backend release manifest generator tests passed\n");
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($tmp);
}

function assertSame(mixed $expected, mixed $actual, string $field): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Expected {$field} to be " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
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

function assertCommandFails(string $command, string $expectedOutput): void
{
    exec($command . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    if ($code === 0 || ! str_contains($text, $expectedOutput)) {
        fwrite(STDERR, "Expected failure containing '{$expectedOutput}', got code {$code}:\n{$text}\n");
        exit(1);
    }
}
