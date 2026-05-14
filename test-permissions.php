<?php

/**
 * 权限系统测试脚本
 *
 * 使用方法: php test-permissions.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

echo "\n";
echo "========================================\n";
echo "       权限系统测试报告\n";
echo "========================================\n\n";

// 1. 测试权限创建
echo "【1】权限统计\n";
echo '   总权限数: '.Permission::count()."\n";
echo "   按模块分组:\n";
$grouped = Permission::all()->groupBy('module');
foreach ($grouped as $module => $permissions) {
    echo "   - {$module}: ".$permissions->count()." 个\n";
}
echo "\n";

// 2. 测试角色权限
echo "【2】角色权限分配\n";
$roles = Role::with('permissions')->get();
foreach ($roles as $role) {
    echo "   {$role->name} ({$role->slug}): ".$role->permissions->count()." 个权限\n";
}
echo "\n";

// 3. 测试用户权限（查找第一个有角色的用户）
echo "【3】用户权限测试\n";
$admin = User::whereHas('roles', function ($q) {
    $q->where('slug', 'admin');
})->first();

if ($admin) {
    echo "   测试用户: {$admin->name} (ID: {$admin->id})\n";
    echo '   角色: '.implode(', ', $admin->getRolesList())."\n";
    echo '   权限数量: '.count($admin->getPermissionsList())."\n";
    echo '   是否是管理员: '.($admin->isAdmin() ? '是' : '否')."\n";
    echo '   是否有 invoices.view 权限: '.($admin->hasPermission('invoices.view') ? '是' : '否')."\n";
    echo '   是否有 payments.create 权限: '.($admin->hasPermission('payments.create') ? '是' : '否')."\n";
} else {
    echo "   ⚠ 未找到管理员用户\n";
}
echo "\n";

// 4. 测试店长权限
$storeOwner = User::whereHas('roles', function ($q) {
    $q->where('slug', 'store_owner');
})->first();

if ($storeOwner) {
    echo "   测试店长: {$storeOwner->name} (ID: {$storeOwner->id})\n";
    echo '   权限数量: '.count($storeOwner->getPermissionsList())."\n";
    echo '   是否有 invoices.delete 权限: '.($storeOwner->hasPermission('invoices.delete') ? '是' : '否')."\n";
    echo '   是否有 users.create 权限: '.($storeOwner->hasPermission('users.create') ? '是' : '否')."\n";
} else {
    echo "   ⚠ 未找到店长用户\n";
}
echo "\n";

// 5. 测试店员权限
$storeStaff = User::whereHas('roles', function ($q) {
    $q->where('slug', 'store_staff');
})->first();

if ($storeStaff) {
    echo "   测试店员: {$storeStaff->name} (ID: {$storeStaff->id})\n";
    echo '   权限数量: '.count($storeStaff->getPermissionsList())."\n";
    echo '   是否有 invoices.view 权限: '.($storeStaff->hasPermission('invoices.view') ? '是' : '否')."\n";
    echo '   是否有 invoices.delete 权限: '.($storeStaff->hasPermission('invoices.delete') ? '是' : '否')."\n";
} else {
    echo "   ⚠ 未找到店员用户\n";
}
echo "\n";

// 6. 测试权限方法
echo "【4】权限方法测试\n";
if ($admin) {
    echo "   hasAnyPermission(['invoices.view', 'payments.view']): ".
        ($admin->hasAnyPermission(['invoices.view', 'payments.view']) ? '是' : '否')."\n";
    echo "   hasAllPermissions(['invoices.view', 'payments.view']): ".
        ($admin->hasAllPermissions(['invoices.view', 'payments.view']) ? '是' : '否')."\n";
    echo "   hasAnyRole(['admin', 'store_owner']): ".
        ($admin->hasAnyRole(['admin', 'store_owner']) ? '是' : '否')."\n";
}
echo "\n";

// 7. 测试角色方法
echo "【5】角色方法测试\n";
$adminRole = Role::where('slug', 'admin')->first();
echo '   管理员角色是否有 invoices.view 权限: '.
    ($adminRole->hasPermission('invoices.view') ? '是' : '否')."\n";
echo "\n";

echo "========================================\n";
echo "✅ 权限系统测试完成！\n";
echo "========================================\n\n";

echo "【向后兼容性验证】\n";
echo "   原有方法仍然可用:\n";
if ($admin) {
    echo '   - isAdmin(): '.($admin->isAdmin() ? '✓' : '✗')."\n";
    echo "   - hasRole('admin'): ".($admin->hasRole('admin') ? '✓' : '✗')."\n";
    echo '   - isStoreOwner(): '.($admin->isStoreOwner() ? '✓' : '✗')."\n";
}
echo "\n";

echo "【新增功能】\n";
echo "   新增权限方法:\n";
echo "   - hasPermission()\n";
echo "   - hasAnyPermission()\n";
echo "   - hasAllPermissions()\n";
echo "   - getPermissionsList()\n";
echo "   - getRolesList()\n";
echo "\n";
