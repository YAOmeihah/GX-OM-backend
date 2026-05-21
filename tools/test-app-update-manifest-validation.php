<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/tools/validate-app-update-manifest.php';
$tmp = sys_get_temp_dir() . '/gx-om-update-manifest-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

try {
    $apk = $tmp . '/app-release.apk';
    file_put_contents($apk, 'fixture apk bytes');
    $hash = hash_file('sha256', $apk);
    $manifest = $tmp . '/update.json';
    file_put_contents($manifest, json_encode([
        'versionCode' => 18,
        'versionName' => '1.0.17',
        'url' => 'https://api-gx-om.hrlni.cn/app_update/app-release.apk',
        'sha256' => $hash,
        'packageName' => 'com.java_gx_om',
        'certificateSha256' => '7f9401a701fd82ca10d1a598321cdeeee4e9becbea77479b9afd9d4c684fc548',
        'channel' => 'production',
        'force' => false,
        'description' => 'fixture',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest), 'packageName must be com.java.gx_om');
    assertCommandFails("php " . escapeshellarg($validator) . " --expected-version-code=18 " . escapeshellarg($manifest), 'packageName must be com.java.gx_om');

    $json = json_decode((string) file_get_contents($manifest), true);
    $json['packageName'] = 'com.java.gx_om';
    file_put_contents($manifest, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    assertCommandSucceeds("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --apk=" . escapeshellarg($apk) . " --expected-version-code=18 --expected-version-name=1.0.17 --expected-certificate-sha256=7f9401a701fd82ca10d1a598321cdeeee4e9becbea77479b9afd9d4c684fc548");
    assertCommandSucceeds("php " . escapeshellarg($validator) . " --apk=" . escapeshellarg($apk) . " --expected-version-code=18 --expected-version-name=1.0.17 --expected-certificate-sha256=7f9401a701fd82ca10d1a598321cdeeee4e9becbea77479b9afd9d4c684fc548 " . escapeshellarg($manifest));
    assertCommandSucceeds("php " . escapeshellarg($validator) . " --apk " . escapeshellarg($apk) . " " . escapeshellarg($manifest) . " --expected-version-code 18");
    assertCommandSucceeds("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --apk " . escapeshellarg($apk) . " --expected-version-code 18");
    $secondManifest = $tmp . '/second-update.json';
    copy($manifest, $secondManifest);
    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " " . escapeshellarg($secondManifest), 'Multiple manifest paths provided');
    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --apk=" . escapeshellarg($apk) . " --expected-version-code=19", 'versionCode does not match expected value');
    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --expected-version-name=1.0.18", 'versionName does not match expected value');
    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --expected-certificate-sha256=0000000000000000000000000000000000000000000000000000000000000000", 'certificateSha256 does not match expected value');

    $json['sha256'] = ['not-a-string'];
    file_put_contents($manifest, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    assertCommandFailsWithoutFatal(
        "php " . escapeshellarg($validator) . " --apk " . escapeshellarg($apk) . " " . escapeshellarg($manifest),
        'sha256 must be 64 lowercase hex characters',
        'sha256 does not match APK'
    );

    $json['sha256'] = $hash;
    file_put_contents($manifest, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($apk, 'changed apk bytes');
    assertCommandFails("php " . escapeshellarg($validator) . " " . escapeshellarg($manifest) . " --apk=" . escapeshellarg($apk), 'sha256 does not match APK');

    fwrite(STDOUT, "App update manifest validator tests passed\n");
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($tmp);
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

function assertCommandFailsWithoutFatal(string $command, string $expectedOutput, string $unexpectedOutput): void
{
    exec($command . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    if (
        $code === 0 ||
        ! str_contains($text, $expectedOutput) ||
        str_contains($text, $unexpectedOutput) ||
        str_contains($text, 'Fatal error') ||
        str_contains($text, 'TypeError')
    ) {
        fwrite(STDERR, "Expected clean failure containing '{$expectedOutput}' without '{$unexpectedOutput}', got code {$code}:\n{$text}\n");
        exit(1);
    }
}
