<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 更新角色定义，将store_manager重命名为store_owner
     */
    public function up(): void
    {
        // 更新现有的store_manager角色为store_owner
        $updated = DB::table('roles')
            ->where('slug', 'store_manager')
            ->update([
                'name' => '店长',
                'slug' => 'store_owner',
                'description' => '在其所属门店中拥有完全管理权限',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            \Log::info("权限架构重构：已更新 {$updated} 个角色记录，store_manager -> store_owner");
        } else {
            // 如果没有找到store_manager角色，创建store_owner角色
            DB::table('roles')->insert([
                'name' => '店长',
                'slug' => 'store_owner',
                'description' => '在其所属门店中拥有完全管理权限',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \Log::info('权限架构重构：已创建新的 store_owner 角色');
        }

        // 验证角色更新结果
        $storeOwnerRole = DB::table('roles')->where('slug', 'store_owner')->first();
        if (! $storeOwnerRole) {
            throw new \Exception('角色更新失败：未找到 store_owner 角色');
        }

        \Log::info('权限架构重构：角色系统更新完成，store_owner 角色已就绪');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚：将store_owner改回store_manager
        $updated = DB::table('roles')
            ->where('slug', 'store_owner')
            ->update([
                'name' => '门店经理',
                'slug' => 'store_manager',
                'description' => '管理门店的所有业务',
                'updated_at' => now(),
            ]);

        \Log::info("权限架构重构回滚：已恢复 {$updated} 个角色记录，store_owner -> store_manager");
    }
};
