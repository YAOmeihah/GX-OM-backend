<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = file_get_contents($root.'/scripts/update-backend.sh');

if ($script === false) {
    fwrite(STDERR, "Unable to read update-backend.sh\n");
    exit(1);
}

assertContains($script, 'ensure_storage_link()', 'update script should define an explicit storage link guard');
assertContains($script, 'public/storage 链接已存在且指向正确，跳过创建', 'existing valid storage link should be skipped cleanly');
assertContains($script, 'ensure_storage_link', 'deploy flow should use the storage link guard');
assertDoesNotContain($script, 'run_artisan storage:link || true', 'deploy flow should not call storage:link and hide the failure');

fwrite(STDOUT, "Backend update script policy tests passed\n");

function assertContains(string $haystack, string $needle, string $message): void
{
    if (str_contains($haystack, $needle)) {
        return;
    }

    fwrite(STDERR, $message."\n");
    exit(1);
}

function assertDoesNotContain(string $haystack, string $needle, string $message): void
{
    if (! str_contains($haystack, $needle)) {
        return;
    }

    fwrite(STDERR, $message."\n");
    exit(1);
}
