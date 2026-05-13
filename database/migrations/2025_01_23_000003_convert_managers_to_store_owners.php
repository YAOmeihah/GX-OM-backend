<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 将现有的门店管理员用户转换为店长角色，确保权限连续性
     */
    public function up(): void
    {
        // 获取store_owner角色ID
        $storeOwnerRole = DB::table('roles')->where('slug', 'store_owner')->first();
        
        if (!$storeOwnerRole) {
            throw new \Exception("未找到 store_owner 角色，请先运行角色更新迁移");
        }

        // 获取所有当前是门店管理员的用户（从备份表中获取）
        $storeManagerUsers = DB::table('temp_store_managers')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        if (empty($storeManagerUsers)) {
            \Log::info("权限架构重构：没有找到需要转换的门店管理员用户");
            return;
        }

        $convertedCount = 0;
        $skippedCount = 0;

        // 为这些用户分配store_owner角色（如果还没有的话）
        foreach ($storeManagerUsers as $userId) {
            // 检查用户是否已经有store_owner角色
            $existingRole = DB::table('role_user')
                ->where('user_id', $userId)
                ->where('role_id', $storeOwnerRole->id)
                ->exists();

            if (!$existingRole) {
                // 分配store_owner角色
                DB::table('role_user')->insert([
                    'user_id' => $userId,
                    'role_id' => $storeOwnerRole->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $convertedCount++;
            } else {
                $skippedCount++;
            }
        }

        \Log::info("权限架构重构：用户角色转换完成 - 转换: {$convertedCount}, 跳过: {$skippedCount}");

        // 验证转换结果
        $totalStoreOwners = DB::table('role_user')
            ->where('role_id', $storeOwnerRole->id)
            ->count();

        \Log::info("权限架构重构：当前共有 {$totalStoreOwners} 个用户拥有店长角色");

        // 验证所有原门店管理员都已获得店长角色
        $unconvertedManagers = DB::table('temp_store_managers')
            ->whereNotExists(function($query) use ($storeOwnerRole) {
                $query->select(DB::raw(1))
                    ->from('role_user')
                    ->where('role_id', $storeOwnerRole->id)
                    ->whereColumn('role_user.user_id', 'temp_store_managers.user_id');
            })
            ->count();

        if ($unconvertedManagers > 0) {
            throw new \Exception("角色转换失败：仍有 {$unconvertedManagers} 个门店管理员未获得店长角色");
        }

        \Log::info("权限架构重构：角色转换验证通过，所有门店管理员都已获得店长角色");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚：移除所有store_owner角色分配
        $storeOwnerRole = DB::table('roles')->where('slug', 'store_owner')->first();
        
        if ($storeOwnerRole) {
            $removedCount = DB::table('role_user')
                ->where('role_id', $storeOwnerRole->id)
                ->delete();
                
            \Log::info("权限架构重构回滚：已移除 {$removedCount} 个店长角色分配");
        }
    }
};
