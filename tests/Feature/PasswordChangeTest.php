<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建测试用户
        $this->user = User::factory()->create([
            'password' => Hash::make('old_password'),
        ]);
    }

    /** @test */
    public function user_can_change_password_with_valid_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => 'new_password123',
            'new_password_confirmation' => 'new_password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => '密码修改成功',
            ]);

        // 验证密码已更改
        $this->user->refresh();
        $this->assertTrue(Hash::check('new_password123', $this->user->password));
    }

    /** @test */
    public function user_cannot_change_password_with_wrong_current_password()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'wrong_password',
            'new_password' => 'new_password123',
            'new_password_confirmation' => 'new_password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /** @test */
    public function user_cannot_change_password_with_mismatched_confirmation()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => 'new_password123',
            'new_password_confirmation' => 'different_password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /** @test */
    public function user_cannot_change_password_with_short_new_password()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => '123',
            'new_password_confirmation' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /** @test */
    public function user_cannot_change_password_to_same_as_current()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => 'old_password',
            'new_password_confirmation' => 'old_password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /** @test */
    public function unauthenticated_user_cannot_change_password()
    {
        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => 'new_password123',
            'new_password_confirmation' => 'new_password123',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function password_change_requires_all_fields()
    {
        Sanctum::actingAs($this->user);

        // 测试缺少current_password
        $response = $this->putJson('/api/user/password', [
            'new_password' => 'new_password123',
            'new_password_confirmation' => 'new_password123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // 测试缺少new_password
        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password_confirmation' => 'new_password123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);

        // 测试缺少new_password_confirmation
        $response = $this->putJson('/api/user/password', [
            'current_password' => 'old_password',
            'new_password' => 'new_password123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password_confirmation']);
    }
}
