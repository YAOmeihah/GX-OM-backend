<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 检查索引是否已存在
        $indexes = DB::select("SHOW INDEX FROM audit_logs WHERE Key_name IN ('idx_scope_business_store', 'idx_actor_store')");
        $existingIndexes = collect($indexes)->pluck('Key_name')->unique()->toArray();

        Schema::table('audit_logs', function (Blueprint $table) use ($existingIndexes) {
            // 复合索引：用于门店业务日志查询（最常用）
            // WHERE scope_type='store' AND business_store_id=?
            if (!in_array('idx_scope_business_store', $existingIndexes)) {
                $table->index(['scope_type', 'business_store_id'], 'idx_scope_business_store');
            }

            // 单列索引：用于按操作者门店查询
            // WHERE actor_store_id=?
            if (!in_array('idx_actor_store', $existingIndexes)) {
                $table->index('actor_store_id', 'idx_actor_store');
            }
        });
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
