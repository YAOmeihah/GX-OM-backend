<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 定义所有权限
        $permissions = [
            // 账单权限
            ['name' => '查看账单', 'slug' => 'invoices.view', 'module' => 'invoices', 'description' => '查看账单列表和详情'],
            ['name' => '创建账单', 'slug' => 'invoices.create', 'module' => 'invoices', 'description' => '创建新账单'],
            ['name' => '编辑账单', 'slug' => 'invoices.update', 'module' => 'invoices', 'description' => '编辑账单信息'],
            ['name' => '删除账单', 'slug' => 'invoices.delete', 'module' => 'invoices', 'description' => '删除账单（仅无付款记录）'],

            // 还款权限
            ['name' => '查看还款', 'slug' => 'payments.view', 'module' => 'payments', 'description' => '查看还款记录'],
            ['name' => '创建还款', 'slug' => 'payments.create', 'module' => 'payments', 'description' => '创建新还款记录'],
            ['name' => '分配还款', 'slug' => 'payments.allocate', 'module' => 'payments', 'description' => '将还款分配到账单'],
            ['name' => '撤销分配', 'slug' => 'payments.revoke', 'module' => 'payments', 'description' => '撤销还款分配'],
            ['name' => '优惠减免', 'slug' => 'payments.discount', 'module' => 'payments', 'description' => '应用优惠减免'],
            ['name' => '删除还款', 'slug' => 'payments.delete', 'module' => 'payments', 'description' => '删除还款记录'],

            // 客户权限
            ['name' => '查看客户', 'slug' => 'customers.view', 'module' => 'customers', 'description' => '查看客户列表和详情'],
            ['name' => '创建客户', 'slug' => 'customers.create', 'module' => 'customers', 'description' => '创建新客户'],
            ['name' => '编辑客户', 'slug' => 'customers.update', 'module' => 'customers', 'description' => '编辑客户信息'],
            ['name' => '删除客户', 'slug' => 'customers.delete', 'module' => 'customers', 'description' => '删除客户'],

            // 门店权限
            ['name' => '查看门店', 'slug' => 'stores.view', 'module' => 'stores', 'description' => '查看门店信息'],
            ['name' => '创建门店', 'slug' => 'stores.create', 'module' => 'stores', 'description' => '创建新门店'],
            ['name' => '编辑门店', 'slug' => 'stores.update', 'module' => 'stores', 'description' => '编辑门店信息'],
            ['name' => '删除门店', 'slug' => 'stores.delete', 'module' => 'stores', 'description' => '删除门店'],

            // 用户权限
            ['name' => '查看用户', 'slug' => 'users.view', 'module' => 'users', 'description' => '查看用户列表'],
            ['name' => '创建用户', 'slug' => 'users.create', 'module' => 'users', 'description' => '创建新用户'],
            ['name' => '编辑用户', 'slug' => 'users.update', 'module' => 'users', 'description' => '编辑用户信息'],
            ['name' => '删除用户', 'slug' => 'users.delete', 'module' => 'users', 'description' => '删除用户'],
            ['name' => '分配角色', 'slug' => 'users.assign-roles', 'module' => 'users', 'description' => '给用户分配角色'],
            ['name' => '分配门店', 'slug' => 'users.assign-stores', 'module' => 'users', 'description' => '给用户分配门店'],

            // 报表权限
            ['name' => '查看仪表盘', 'slug' => 'dashboard.view', 'module' => 'dashboard', 'description' => '查看仪表盘统计'],
            ['name' => '查看报表', 'slug' => 'reports.view', 'module' => 'reports', 'description' => '查看各类报表'],
            ['name' => '导出数据', 'slug' => 'reports.export', 'module' => 'reports', 'description' => '导出Excel/PDF报表'],

            // 审计日志
            ['name' => '查看审计日志', 'slug' => 'audit-logs.view', 'module' => 'audit', 'description' => '查看系统审计日志'],

            // 配置权限
            ['name' => '系统配置', 'slug' => 'config.manage', 'module' => 'config', 'description' => '管理系统配置（S3等）'],
            ['name' => '系统更新', 'slug' => 'system-updates.manage', 'module' => 'system', 'description' => '检查、下载、安装和回滚系统更新'],

            // 附件权限
            ['name' => '上传附件', 'slug' => 'attachments.upload', 'module' => 'attachments', 'description' => '上传附件文件'],
            ['name' => '删除附件', 'slug' => 'attachments.delete', 'module' => 'attachments', 'description' => '删除附件文件'],
        ];

        // 创建权限
        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                $permission
            );
        }

        $this->command->info('✓ 权限创建完成');

        // 分配权限给角色
        $this->assignPermissionsToRoles();
    }

    /**
     * 分配权限给角色
     */
    private function assignPermissionsToRoles(): void
    {
        $admin = Role::where('slug', 'admin')->first();
        $storeOwner = Role::where('slug', 'store_owner')->first();
        $storeStaff = Role::where('slug', 'store_staff')->first();

        if (! $admin || ! $storeOwner || ! $storeStaff) {
            $this->command->warn('⚠ 角色不存在，请先运行 RoleSeeder');

            return;
        }

        // 管理员拥有所有权限（实际通过 Gate::before 已实现，此处可选）
        $allPermissions = Permission::pluck('id');
        $admin->permissions()->sync($allPermissions);
        $this->command->info('✓ 管理员权限分配完成（所有权限）');

        // 店长权限
        $storeOwnerPermissions = Permission::whereIn('slug', [
            // 账单
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete',
            // 还款
            'payments.view', 'payments.create', 'payments.allocate', 'payments.revoke',
            'payments.discount', 'payments.delete',
            // 客户
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            // 门店
            'stores.view',
            // 报表
            'dashboard.view', 'reports.view', 'reports.export',
            // 审计
            'audit-logs.view',
            // 附件
            'attachments.upload', 'attachments.delete',
        ])->pluck('id');

        $storeOwner->permissions()->sync($storeOwnerPermissions);
        $this->command->info('✓ 店长权限分配完成');

        // 店员权限
        $storeStaffPermissions = Permission::whereIn('slug', [
            // 账单
            'invoices.view', 'invoices.create',
            // 还款
            'payments.view', 'payments.create',
            // 客户
            'customers.view', 'customers.create', 'customers.update',
            // 报表
            'dashboard.view',
            // 附件
            'attachments.upload',
        ])->pluck('id');

        $storeStaff->permissions()->sync($storeStaffPermissions);
        $this->command->info('✓ 店员权限分配完成');

        $this->command->info('');
        $this->command->info('====================================');
        $this->command->info('✅ 权限系统初始化完成！');
        $this->command->info('====================================');
    }
}
