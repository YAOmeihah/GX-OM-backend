<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RuntimeConfig;
use App\Models\User;
use App\Services\S3RuntimeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class S3ConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_runtime_s3_config_without_env_file(): void
    {
        $this->actingAsAdmin();

        $envPath = base_path('.env');
        $envBefore = file_exists($envPath) ? file_get_contents($envPath) : null;

        $this->mockSuccessfulS3Connection();

        $response = $this->putJson('/api/config/s3', $this->validS3Payload());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'S3存储配置更新成功',
            ]);

        $envAfter = file_exists($envPath) ? file_get_contents($envPath) : null;

        $this->assertDatabaseHas('runtime_configs', ['key' => 's3-compat']);
        $this->assertDatabaseMissing('runtime_configs', ['value' => 'runtime-secret-key']);
        $this->assertSame($envBefore, $envAfter);
        $this->assertStringNotContainsString('runtime-access-key', $envAfter ?? '');
        $this->assertStringNotContainsString('runtime-secret-key', $envAfter ?? '');
    }

    public function test_attachment_config_exposes_runtime_source_and_extended_s3_fields(): void
    {
        $this->actingAsAdmin();

        app(S3RuntimeConfigService::class)->persist($this->validExtendedS3Payload([
            'url' => 'https://cdn.example.com/runtime-bucket',
            'use_path_style_endpoint' => true,
            'verify' => false,
        ]));

        $response = $this->getJson('/api/config/attachment');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source', 'runtime')
            ->assertJsonPath('data.s3_config.region', 'auto')
            ->assertJsonPath('data.s3_config.bucket', 'runtime-bucket')
            ->assertJsonPath('data.s3_config.endpoint', 'https://s3.example.com')
            ->assertJsonPath('data.s3_config.url', 'https://cdn.example.com/runtime-bucket')
            ->assertJsonPath('data.s3_config.use_path_style_endpoint', true)
            ->assertJsonPath('data.s3_config.verify', false)
            ->assertJsonPath('data.s3_config.access_key', '已配置')
            ->assertJsonPath('data.s3_config.secret_key', '已配置');
    }

    public function test_admin_can_update_extended_runtime_s3_config_and_keep_blank_credentials(): void
    {
        $this->actingAsAdmin();
        app(S3RuntimeConfigService::class)->persist($this->validExtendedS3Payload([
            'access_key' => 'existing-access-key',
            'secret_key' => 'existing-secret-key',
        ]));
        $this->mockSuccessfulS3Connection();

        $response = $this->putJson('/api/config/s3', [
            'access_key' => '',
            'secret_key' => '',
            'region' => 'us-east-1',
            'bucket' => 'gx-om-local',
            'endpoint' => 'http://127.0.0.1:9000',
            'url' => 'http://127.0.0.1:9000/gx-om-local',
            'use_path_style_endpoint' => true,
            'verify' => false,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'S3存储配置更新成功',
            ]);

        $runtimeConfig = RuntimeConfig::where('key', 's3-compat')->firstOrFail()->value;
        $this->assertSame('existing-access-key', $runtimeConfig['access_key']);
        $this->assertSame('existing-secret-key', $runtimeConfig['secret_key']);
        $this->assertSame('gx-om-local', $runtimeConfig['bucket']);
        $this->assertSame('http://127.0.0.1:9000', $runtimeConfig['endpoint']);
        $this->assertSame('http://127.0.0.1:9000/gx-om-local', $runtimeConfig['url']);
        $this->assertTrue($runtimeConfig['use_path_style_endpoint']);
        $this->assertFalse($runtimeConfig['verify']);
    }

    public function test_s3_config_update_requires_credentials_when_none_are_configured(): void
    {
        $this->actingAsAdmin();
        config([
            'filesystems.disks.s3-compat.key' => null,
            'filesystems.disks.s3-compat.secret' => null,
        ]);

        $response = $this->putJson('/api/config/s3', [
            'access_key' => '',
            'secret_key' => '',
            'region' => 'us-east-1',
            'bucket' => 'gx-om-local',
            'endpoint' => 'http://127.0.0.1:9000',
            'url' => 'http://127.0.0.1:9000/gx-om-local',
            'use_path_style_endpoint' => true,
            'verify' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_s3_connection_test_requires_current_credentials_when_no_draft_payload_is_sent(): void
    {
        $this->actingAsAdmin();
        config([
            'filesystems.disks.s3-compat.key' => null,
            'filesystems.disks.s3-compat.secret' => null,
        ]);

        $response = $this->postJson('/api/config/s3/test', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '访问密钥和秘密密钥不能为空',
            ]);
    }

    public function test_draft_s3_connection_test_does_not_persist_candidate_config(): void
    {
        $this->actingAsAdmin();
        $this->mockSuccessfulS3Connection();

        $response = $this->postJson('/api/config/s3/test', $this->validExtendedS3Payload([
            'bucket' => 'draft-bucket',
            'url' => 'https://cdn.example.com/draft-bucket',
            'use_path_style_endpoint' => true,
            'verify' => false,
        ]));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'S3存储连接测试成功',
                'data' => ['status' => 'connected'],
            ]);

        $this->assertDatabaseMissing('runtime_configs', ['key' => 's3-compat']);
    }

    public function test_successful_draft_s3_connection_test_restores_active_config(): void
    {
        $this->actingAsAdmin();
        $expectedConfig = $this->setActiveS3Config([
            'key' => 'active-access-key',
            'secret' => 'active-secret-key',
            'region' => 'active-region',
            'bucket' => 'active-bucket',
            'endpoint' => 'https://active-s3.example.com',
            'url' => 'https://active-cdn.example.com/active-bucket',
            'use_path_style_endpoint' => true,
            'verify' => true,
        ]);
        $this->mockSuccessfulS3Connection();

        $response = $this->postJson('/api/config/s3/test', $this->validExtendedS3Payload([
            'access_key' => 'draft-access-key',
            'secret_key' => 'draft-secret-key',
            'region' => 'draft-region',
            'bucket' => 'draft-bucket',
            'endpoint' => 'https://draft-s3.example.com',
            'url' => 'https://draft-cdn.example.com/draft-bucket',
            'use_path_style_endpoint' => false,
            'verify' => false,
        ]));

        $response->assertOk();
        $this->assertActiveS3Config($expectedConfig);
    }

    public function test_failed_s3_config_update_restores_active_config(): void
    {
        $this->actingAsAdmin();
        $expectedConfig = $this->setActiveS3Config([
            'key' => 'active-access-key',
            'secret' => 'active-secret-key',
            'region' => 'active-region',
            'bucket' => 'active-bucket',
            'endpoint' => 'https://active-s3.example.com',
            'url' => 'https://active-cdn.example.com/active-bucket',
            'use_path_style_endpoint' => true,
            'verify' => true,
        ]);
        $this->mockFailedS3Connection();

        $response = $this->putJson('/api/config/s3', $this->validExtendedS3Payload([
            'access_key' => 'draft-access-key',
            'secret_key' => 'draft-secret-key',
            'region' => 'draft-region',
            'bucket' => 'draft-bucket',
            'endpoint' => 'https://draft-s3.example.com',
            'url' => 'https://draft-cdn.example.com/draft-bucket',
            'use_path_style_endpoint' => false,
            'verify' => false,
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '配置测试失败',
            ]);
        $this->assertActiveS3Config($expectedConfig);
    }

    public function test_admin_can_clear_runtime_s3_config_to_restore_env_defaults(): void
    {
        $this->actingAsAdmin();
        app(S3RuntimeConfigService::class)->persist($this->validExtendedS3Payload());

        $response = $this->deleteJson('/api/config/s3/runtime');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'S3运行时配置已恢复为环境配置',
            ]);

        $this->assertDatabaseMissing('runtime_configs', ['key' => 's3-compat']);
    }

    public function test_s3_connection_test_hides_raw_exception_details(): void
    {
        $this->actingAsAdmin();
        config([
            'filesystems.disks.s3-compat.key' => 'configured-access-key',
            'filesystems.disks.s3-compat.secret' => 'configured-secret-key',
        ]);
        $this->mockFailedS3Connection();

        $response = $this->postJson('/api/config/s3/test');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '配置测试失败',
            ]);

        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    public function test_s3_config_update_hides_raw_connection_failure_details(): void
    {
        $this->actingAsAdmin();
        $this->mockFailedS3Connection();

        $response = $this->putJson('/api/config/s3', $this->validS3Payload());

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '配置测试失败',
            ]);

        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
        $this->assertDatabaseMissing('runtime_configs', ['key' => 's3-compat']);
    }

    public function test_presigned_upload_client_uses_runtime_s3_client_options_for_ssl_verification(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/AttachmentController.php'));

        $this->assertStringContainsString('S3RuntimeConfigService::class', $controllerSource);
        $this->assertStringContainsString('s3ClientOptions', $controllerSource);
        $this->assertStringNotContainsString("'verify' => false", $controllerSource);
    }

    public function test_s3_client_options_honor_nested_ssl_verify_config(): void
    {
        config(['filesystems.disks.s3-compat.http.verify' => false]);
        config(['filesystems.disks.s3-compat.options.http.verify' => false]);

        $options = app(S3RuntimeConfigService::class)->s3ClientOptions();

        $this->assertFalse($options['http']['verify']);
    }

    private function actingAsAdmin(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => '系统管理员',
            'description' => '拥有系统所有权限',
        ]);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);
        Sanctum::actingAs($admin);
    }

    private function validS3Payload(): array
    {
        return $this->validExtendedS3Payload();
    }

    private function validExtendedS3Payload(array $overrides = []): array
    {
        return array_merge([
            'access_key' => 'runtime-access-key',
            'secret_key' => 'runtime-secret-key',
            'region' => 'auto',
            'bucket' => 'runtime-bucket',
            'endpoint' => 'https://s3.example.com',
            'url' => 'https://cdn.example.com/runtime-bucket',
            'use_path_style_endpoint' => false,
            'verify' => true,
        ], $overrides);
    }

    private function setActiveS3Config(array $overrides = []): array
    {
        $config = array_merge([
            'key' => 'active-access-key',
            'secret' => 'active-secret-key',
            'region' => 'active-region',
            'bucket' => 'active-bucket',
            'endpoint' => 'https://active-s3.example.com',
            'url' => 'https://active-cdn.example.com/active-bucket',
            'use_path_style_endpoint' => true,
            'verify' => true,
        ], $overrides);

        config([
            'filesystems.disks.s3-compat.key' => $config['key'],
            'filesystems.disks.s3-compat.secret' => $config['secret'],
            'filesystems.disks.s3-compat.region' => $config['region'],
            'filesystems.disks.s3-compat.bucket' => $config['bucket'],
            'filesystems.disks.s3-compat.endpoint' => $config['endpoint'],
            'filesystems.disks.s3-compat.url' => $config['url'],
            'filesystems.disks.s3-compat.use_path_style_endpoint' => $config['use_path_style_endpoint'],
            'filesystems.disks.s3-compat.http.verify' => $config['verify'],
            'filesystems.disks.s3-compat.options.http.verify' => $config['verify'],
        ]);

        return $config;
    }

    private function assertActiveS3Config(array $expected): void
    {
        $this->assertSame($expected['key'], config('filesystems.disks.s3-compat.key'));
        $this->assertSame($expected['secret'], config('filesystems.disks.s3-compat.secret'));
        $this->assertSame($expected['region'], config('filesystems.disks.s3-compat.region'));
        $this->assertSame($expected['bucket'], config('filesystems.disks.s3-compat.bucket'));
        $this->assertSame($expected['endpoint'], config('filesystems.disks.s3-compat.endpoint'));
        $this->assertSame($expected['url'], config('filesystems.disks.s3-compat.url'));
        $this->assertSame($expected['use_path_style_endpoint'], config('filesystems.disks.s3-compat.use_path_style_endpoint'));
        $this->assertSame($expected['verify'], config('filesystems.disks.s3-compat.http.verify'));
        $this->assertSame($expected['verify'], config('filesystems.disks.s3-compat.options.http.verify'));
    }

    private function mockSuccessfulS3Connection(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('files')->once()->with('', true)->andReturn([]);
        Storage::shouldReceive('forgetDisk')->zeroOrMoreTimes()->with('s3-compat');
        Storage::shouldReceive('disk')->once()->with('s3-compat')->andReturn($disk);
    }

    private function mockFailedS3Connection(): void
    {
        Storage::shouldReceive('forgetDisk')->zeroOrMoreTimes()->with('s3-compat');
        Storage::shouldReceive('disk')
            ->once()
            ->with('s3-compat')
            ->andThrow(new \RuntimeException('Access denied: SECRET_INTERNAL_DETAIL'));
    }
}
