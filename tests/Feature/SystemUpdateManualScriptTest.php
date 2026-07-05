<?php

namespace Tests\Feature;

use Tests\TestCase;

class SystemUpdateManualScriptTest extends TestCase
{
    public function test_manual_update_script_uses_local_packages_and_preserves_runtime_paths(): void
    {
        $scriptPath = base_path('scripts/update-backend.sh');

        $this->assertFileExists($scriptPath);

        $script = (string) file_get_contents($scriptPath);

        $this->assertStringContainsString('sha256sum', $script);
        $this->assertStringContainsString('public/app_update', $script);
        $this->assertStringContainsString('public/.user.ini', $script);
        $this->assertStringContainsString('public/storage', $script);
        $this->assertStringContainsString('bootstrap/cache', $script);
        $this->assertStringContainsString('storage', $script);
        $this->assertStringContainsString('artisan down', $script);
        $this->assertStringContainsString('artisan migrate --force', $script);
        $this->assertStringContainsString('artisan optimize:clear', $script);
        $this->assertStringContainsString('artisan storage:link --force', $script);
        $this->assertStringContainsString('artisan up', $script);
        $this->assertStringContainsString('--tag', $script);
        $this->assertStringContainsString('GITHUB_RELEASE_TOKEN', $script);
        $this->assertStringContainsString('SYSTEM_UPDATE_GITHUB_TOKEN', $script);
        $this->assertStringContainsString('GITHUB_RELEASE_OWNER', $script);
        $this->assertStringContainsString('GITHUB_RELEASE_REPO', $script);
        $this->assertStringContainsString('/releases/tags/', $script);
        $this->assertStringContainsString('/releases/assets/', $script);
        $this->assertStringContainsString('application/octet-stream', $script);
    }
}
