<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 添加 invoices 表联合索引
 *
 * 优化客户列表查询性能：
 * - 客户欠款统计子查询使用 customer_id + store_id + status 过滤
 * - 添加联合索引可避免全表扫描，将查询时间从 O(n) 降至 O(log n)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // 联合索引：优化客户欠款统计查询
            // 索引顺序：customer_id (最常用) -> store_id (次常用) -> status (过滤条件)
            $table->index(
                ['customer_id', 'store_id', 'status'],
                'idx_invoices_customer_store_status'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_customer_store_status');
        });
    }
};
