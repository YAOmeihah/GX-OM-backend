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
            // 日志作用域类型：global（全局）或 store（门店）
            $table->enum('scope_type', ['global', 'store'])
                ->nullable()
                ->after('store_id')
                ->comment('日志作用域类型');

            // 业务归属门店ID（门店业务日志必填，全局日志为null）
            $table->unsignedBigInteger('business_store_id')
                ->nullable()
                ->after('scope_type')
                ->comment('业务归属门店ID');

            // 操作者所在门店ID（可选，记录操作时用户所在的门店）
            $table->unsignedBigInteger('actor_store_id')
                ->nullable()
                ->after('business_store_id')
                ->comment('操作者所在门店ID');

            // 添加索引以提升查询性能
            $table->index(['scope_type', 'business_store_id'], 'idx_scope_business_store');
            $table->index('actor_store_id', 'idx_actor_store');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // 删除索引
            $table->dropIndex('idx_scope_business_store');
            $table->dropIndex('idx_actor_store');

            // 删除字段
            $table->dropColumn(['scope_type', 'business_store_id', 'actor_store_id']);
        });
    }
};
