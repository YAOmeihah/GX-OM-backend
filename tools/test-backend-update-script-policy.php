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
assertContains($script, 'download_public_release_assets()', 'public repository releases should use direct download URLs first');
assertContains($script, 'SYSTEM_UPDATE_GITHUB_DOWNLOAD_BASE_URL', 'download base URL should be configurable for trusted mirrors');
assertContains($script, '使用公开 Release 直链下载', 'script should tell the operator when public direct download is used');
assertContains($script, '公开直链下载失败，尝试使用 GitHub API 下载', 'token-based API download should remain as fallback');
assertDoesNotContain($script, 'run_artisan storage:link || true', 'deploy flow should not call storage:link and hide the failure');
assertDoesNotContain($script, '--retry-connrefused', 'update script should not require curl --retry-connrefused because older server curl versions do not support it');

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
