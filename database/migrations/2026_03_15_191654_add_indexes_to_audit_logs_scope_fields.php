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
        try {
            $table = Schema::table('audit_logs', function (Blueprint $table) {
                // 复合索引：用于门店业务日志查询（最常用）
                // WHERE scope_type='store' AND business_store_id=?
                $table->index(['scope_type', 'business_store_id'], 'idx_scope_business_store');
            });
        } catch (\Exception $e) {
            // 索引可能已存在（常见于 SQLite 等不支持的驱动）
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                // 单列索引：用于按操作者门店查询
                // WHERE actor_store_id=?
                $table->index('actor_store_id', 'idx_actor_store');
            });
        } catch (\Exception $e) {
            // 索引可能已存在
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // 使用 dropIndexIfExists（Laravel 11+）
            try {
                $table->dropIndex('idx_scope_business_store');
            } catch (\Exception $e) {
                // 索引不存在，忽略
            }

            try {
                $table->dropIndex('idx_actor_store');
            } catch (\Exception $e) {
                // 索引不存在，忽略
            }
        });
    }
};
