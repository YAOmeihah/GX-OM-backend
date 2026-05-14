<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * 门店授权策略
 *
 * 集中管理所有与 Store 模型相关的权限检查逻辑
 */
class StorePolicy
{
    use HandlesAuthorization;

    /**
     * 查看门店列表
     * 所有已认证用户都可以查看（但只能看到自己有权限的门店）
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * 查看单个门店详情
     * 管理员或属于该门店的用户可以查看
     */
    public function view(User $user, Store $store): bool
    {
        return $user->isAdmin() || $user->belongsToStore($store->id);
    }

    /**
     * 创建门店
     * 仅管理员可以创建
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 更新门店
     * 管理员或该门店的店长可以更新
     */
    public function update(User $user, Store $store): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($store->id);
    }

    /**
     * 删除门店
     * 仅管理员可以删除
     */
    public function delete(User $user, Store $store): bool
    {
        return $user->isAdmin();
    }

    /**
     * 管理门店（用于批量操作等）
     * 管理员或该门店的店长可以管理
     */
    public function manage(User $user, Store $store): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($store->id);
    }
}
