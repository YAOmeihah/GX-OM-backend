<?php

namespace Tests\Feature;

use App\Models\Role;
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

    public function test_s3_connection_test_hides_raw_exception_details(): void
    {
        $this->actingAsAdmin();
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
        return [
            'access_key' => 'runtime-access-key',
            'secret_key' => 'runtime-secret-key',
            'region' => 'auto',
            'bucket' => 'runtime-bucket',
            'endpoint' => 'https://s3.example.com',
        ];
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
