<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * 客户授权策略
 *
 * 集中管理所有与 Customer 模型相关的权限检查逻辑
 */
class CustomerPolicy
{
    use HandlesAuthorization;

    /**
     * 查看客户列表
     * 所有已认证用户都可以查看
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * 查看单个客户详情
     * 管理员可以查看所有客户；普通用户只能查看其所属门店的客户
     */
    public function view(User $user, Customer $customer): bool
    {
        return $user->isAdmin() || $user->belongsToStore($customer->store_id);
    }

    /**
     * 创建客户
     * 所有已认证用户都可以创建
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 更新客户信息
     * 管理员可以更新所有客户；普通用户只能更新其所属门店的客户
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->isAdmin() || $user->belongsToStore($customer->store_id);
    }

    /**
     * 删除客户
     * 仅管理员可以删除
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }

    /**
     * 查看客户欠款详情
     * 用户必须至少属于一个门店
     */
    public function viewDebt(User $user, Customer $customer): bool
    {
        return $user->isAdmin() || $user->stores()->exists();
    }
}
