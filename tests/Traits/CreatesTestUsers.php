<?php

namespace Tests\Traits;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;

/**
 * 测试用户创建辅助 Trait
 *
 * 提供统一的用户创建方法，正确处理角色关联
 */
trait CreatesTestUsers
{
    /**
     * 创建管理员用户
     */
    protected function createAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->assignRole($user, 'admin');

        return $user;
    }

    /**
     * 创建店长用户
     */
    protected function createStoreOwner(array $attributes = [], ?Store $store = null): User
    {
        $user = User::factory()->create($attributes);
        $this->assignRole($user, 'store_owner');

        if ($store) {
            $user->stores()->attach($store->id);
        }

        return $user;
    }

    /**
     * 创建店员用户
     */
    protected function createStoreStaff(array $attributes = [], ?Store $store = null): User
    {
        $user = User::factory()->create($attributes);
        $this->assignRole($user, 'store_staff');

        if ($store) {
            $user->stores()->attach($store->id);
        }

        return $user;
    }

    /**
     * 创建普通用户（无角色）
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * 为用户分配角色
     */
    protected function assignRole(User $user, string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();

        if (! $role) {
            // 如果角色不存在，创建一个
            $role = Role::create([
                'name' => ucfirst(str_replace('_', ' ', $roleSlug)),
                'slug' => $roleSlug,
                'description' => "Test role: {$roleSlug}",
            ]);
        }

        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    /**
     * 确保基础角色存在
     */
    protected function ensureRolesExist(): void
    {
        $roles = [
            ['name' => '系统管理员', 'slug' => 'admin', 'description' => '拥有系统所有权限'],
            ['name' => '店长', 'slug' => 'store_owner', 'description' => '门店管理员'],
            ['name' => '店员', 'slug' => 'store_staff', 'description' => '门店普通员工'],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(
                ['slug' => $roleData['slug']],
                $roleData
            );
        }
    }
}
