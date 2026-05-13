<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 检查username="admin"是否已被其他用户占用
        $existingUserWithAdminUsername = User::where('username', 'admin')
            ->where('email', '!=', 'admin@example.com')
            ->first();

        if ($existingUserWithAdminUsername) {
            // 如果其他用户占用了username="admin"，给他们分配一个新的username
            $newUsername = 'user_' . $existingUserWithAdminUsername->id;
            $existingUserWithAdminUsername->update(['username' => $newUsername]);
            Log::info("AdminSeeder: 用户ID {$existingUserWithAdminUsername->id} 的username从'admin'更改为'{$newUsername}'");
        }

        // 创建管理员用户
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => '系统管理员',
                'username' => 'admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        Log::info("AdminSeeder: 管理员用户已创建/更新 - ID: {$admin->id}, Username: {$admin->username}");

        // 分配管理员角色
        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->sync([$adminRole->id]);
            Log::info("AdminSeeder: 已为管理员用户分配admin角色");
        } else {
            Log::warning("AdminSeeder: 未找到admin角色，请确保RoleSeeder已运行");
        }
    }
}