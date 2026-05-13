<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 将现有审计日志数据迁移到新的作用域模型
     */
    public function up(): void
    {
        $this->migrateGlobalLogs();
        $this->migrateStoreLogs();
        $this->migrateSystemManagementLogs();
    }

    /**
     * 迁移全局日志（登录、登出等系统级操作）
     */
    private function migrateGlobalLogs(): void
    {
        // 全局操作类型
        DB::table('audit_logs')
            ->whereIn('action', ['login', 'logout', 'view', 'upload', 'download', 'export', 'import'])
            ->update([
                'scope_type' => 'global',
                'business_store_id' => null,
                'actor_store_id' => DB::raw('store_id'), // 保留操作者当时所在门店
            ]);

        echo "✓ 已迁移全局操作日志\n";
    }

    /**
     * 迁移门店业务日志
     */
    private function migrateStoreLogs(): void
    {
        // 门店业务模型
        $storeModels = [
            'App\\Models\\Invoice',
            'App\\Models\\InvoiceItem',
            'App\\Models\\Payment',
            'App\\Models\\PaymentAllocation',
            'App\\Models\\PaymentDiscount',
            'App\\Models\\Customer',
        ];

        DB::table('audit_logs')
            ->whereIn('auditable_type', $storeModels)
            ->whereNotNull('store_id')
            ->update([
                'scope_type' => 'store',
                'business_store_id' => DB::raw('store_id'),
                'actor_store_id' => DB::raw('store_id'),
            ]);

        echo "✓ 已迁移门店业务日志\n";
    }

    /**
     * 迁移系统管理对象日志（用户、门店、角色等）
     */
    private function migrateSystemManagementLogs(): void
    {
        // 系统管理模型
        $systemModels = [
            'App\\Models\\User',
            'App\\Models\\Store',
            'App\\Models\\Role',
            'App\\Models\\Permission',
            'App\\Models\\Attachment',
        ];

        DB::table('audit_logs')
            ->whereIn('auditable_type', $systemModels)
            ->update([
                'scope_type' => 'global',
                'business_store_id' => null,
                'actor_store_id' => DB::raw('store_id'),
            ]);

        echo "✓ 已迁移系统管理对象日志\n";
    }

    /**
     * Reverse the migrations.
     *
     * 回滚时清空新字段
     */
    public function down(): void
    {
        DB::table('audit_logs')->update([
            'scope_type' => null,
            'business_store_id' => null,
            'actor_store_id' => null,
        ]);
    }
};
