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
     * 备份当前is_manager=true的用户数据，用于角色转换
     */
    public function up(): void
    {
        // 创建临时表备份门店管理员数据
        Schema::create('temp_store_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'store_id']);
            $table->index('user_id');
            $table->index('store_id');
        });

        // 备份当前所有is_manager=true的数据
        DB::statement('
            INSERT INTO temp_store_managers (user_id, store_id, created_at, updated_at)
            SELECT user_id, store_id, created_at, updated_at 
            FROM store_user 
            WHERE is_manager = 1
        ');

        // 记录备份统计信息
        $backupCount = DB::table('temp_store_managers')->count();

        // 输出备份信息到日志
        \Log::info("权限架构重构：已备份 {$backupCount} 条门店管理员数据到 temp_store_managers 表");

        // 验证备份数据的完整性
        $originalCount = DB::table('store_user')->where('is_manager', true)->count();

        if ($backupCount !== $originalCount) {
            throw new \Exception("数据备份失败：原始数据 {$originalCount} 条，备份数据 {$backupCount} 条");
        }

        \Log::info('权限架构重构：数据备份验证通过，原始数据和备份数据数量一致');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temp_store_managers');
        \Log::info('权限架构重构：已删除临时备份表 temp_store_managers');
    }
};
