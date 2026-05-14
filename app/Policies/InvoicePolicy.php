<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * 账单授权策略
 *
 * 集中管理所有与 Invoice 模型相关的权限检查逻辑
 */
class InvoicePolicy
{
    use HandlesAuthorization;

    /**
     * 查看账单列表
     * 所有已认证用户都可以查看（但会被门店过滤）
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * 查看单个账单详情
     * 管理员或属于该门店的用户可以查看
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() || $user->belongsToStore($invoice->store_id);
    }

    /**
     * 创建账单
     * 管理员或属于目标门店的用户可以创建
     *
     * @param  int|null  $storeId  目标门店ID（从请求中获取）
     */
    public function create(User $user, ?int $storeId = null): bool
    {
        if ($storeId === null) {
            return false;
        }

        return $user->isAdmin() || $user->belongsToStore($storeId);
    }

    /**
     * 更新账单
     * 管理员或该门店的店长可以更新
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($invoice->store_id);
    }

    /**
     * 删除账单
     * 管理员或该门店的店长可以删除
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($invoice->store_id);
    }
}
