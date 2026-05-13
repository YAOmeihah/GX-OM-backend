<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 创建系统角色
        $roles = [
            [
                'name' => '系统管理员',
                'slug' => 'admin',
                'description' => '拥有系统所有权限',
                'is_system' => true
            ],
            [
                'name' => '店长',
                'slug' => 'store_owner',
                'description' => '在其所属门店中拥有完全管理权限',
                'is_system' => true
            ],
            [
                'name' => '店员',
                'slug' => 'store_staff',
                'description' => '处理日常业务',
                'is_system' => true
            ]
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                $role
            );
        }
    }
} 