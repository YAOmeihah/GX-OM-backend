<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // 先删除外键约束（use column array instead of named foreign for SQLite compat）
            $table->dropForeign(['store_id']);
        });

        // SQLite 需要在删列前先删索引
        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('audit_logs_store_id_index');
            });
        } catch (\Exception $e) {
            // 索引可能不存在
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            // 再删除旧架构的门店字段
            $table->dropColumn(['store_id', 'store_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // 恢复旧字段（用于回滚）
            $table->unsignedBigInteger('store_id')->nullable()->after('actor_store_id');
            $table->string('store_name')->nullable()->after('store_id');

            // 添加外键约束
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');

            // 添加索引
            $table->index('store_id');
        });
    }
};
