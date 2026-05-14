<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 移除store_user表中的is_manager字段，清理相关数据结构
     */
    public function up(): void
    {
        // 在删除字段前，最后一次验证数据迁移的完整性
        $this->validateDataMigration();

        // 移除store_user表中的is_manager字段
        Schema::table('store_user', function (Blueprint $table) {
            $table->dropColumn('is_manager');
        });

        \Log::info('权限架构重构：已从 store_user 表中移除 is_manager 字段');

        // 清理临时备份表
        Schema::dropIfExists('temp_store_managers');

        \Log::info('权限架构重构：已清理临时备份表 temp_store_managers');

        // 验证表结构更新
        $this->validateTableStructure();

        \Log::info('权限架构重构：数据库结构修改完成，权限系统已简化为纯角色权限系统');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 重新添加is_manager字段
        Schema::table('store_user', function (Blueprint $table) {
            $table->boolean('is_manager')->default(false);
        });

        // 重新创建临时表（用于回滚数据恢复）
        Schema::create('temp_store_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'store_id']);
        });

        // 根据store_owner角色恢复is_manager数据
        $storeOwnerRole = DB::table('roles')->where('slug', 'store_owner')->first();

        if ($storeOwnerRole) {
            // 获取所有拥有store_owner角色的用户
            $storeOwners = DB::table('role_user')
                ->where('role_id', $storeOwnerRole->id)
                ->pluck('user_id');

            // 为这些用户在其所属的所有门店中设置is_manager=true
            foreach ($storeOwners as $userId) {
                $userStores = DB::table('store_user')
                    ->where('user_id', $userId)
                    ->pluck('store_id');

                foreach ($userStores as $storeId) {
                    DB::table('store_user')
                        ->where('user_id', $userId)
                        ->where('store_id', $storeId)
                        ->update(['is_manager' => true]);
                }
            }
        }

        \Log::info('权限架构重构回滚：已恢复 store_user 表的 is_manager 字段和相关数据');
    }

    /**
     * 验证数据迁移的完整性
     */
    private function validateDataMigration(): void
    {
        // 验证所有原门店管理员都已获得store_owner角色
        $storeOwnerRole = DB::table('roles')->where('slug', 'store_owner')->first();

        if (! $storeOwnerRole) {
            throw new \Exception('验证失败：未找到 store_owner 角色');
        }

        // 检查临时备份表是否存在
        if (! Schema::hasTable('temp_store_managers')) {
            throw new \Exception('验证失败：未找到临时备份表 temp_store_managers');
        }

        // 验证所有备份的门店管理员都已获得店长角色
        $unconvertedManagers = DB::table('temp_store_managers')
            ->whereNotExists(function ($query) use ($storeOwnerRole) {
                $query->select(DB::raw(1))
                    ->from('role_user')
                    ->where('role_id', $storeOwnerRole->id)
                    ->whereColumn('role_user.user_id', 'temp_store_managers.user_id');
            })
            ->count();

        if ($unconvertedManagers > 0) {
            throw new \Exception("验证失败：仍有 {$unconvertedManagers} 个门店管理员未获得店长角色");
        }

        \Log::info('权限架构重构：数据迁移完整性验证通过');
    }

    /**
     * 验证表结构更新
     */
    private function validateTableStructure(): void
    {
        // 验证is_manager字段已被移除
        if (Schema::hasColumn('store_user', 'is_manager')) {
            throw new \Exception('验证失败：is_manager 字段仍然存在于 store_user 表中');
        }

        // 验证临时表已被清理
        if (Schema::hasTable('temp_store_managers')) {
            throw new \Exception('验证失败：临时备份表 temp_store_managers 仍然存在');
        }

        \Log::info('权限架构重构：表结构验证通过');
    }
};
