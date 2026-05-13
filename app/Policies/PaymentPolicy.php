<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * 还款记录授权策略
 * 
 * 集中管理所有与 Payment 模型相关的权限检查逻辑
 */
class PaymentPolicy
{
    use HandlesAuthorization;

    /**
     * 查看还款列表
     * 所有已认证用户都可以查看（但会被门店过滤）
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * 查看单个还款详情
     * 管理员或属于该门店的用户可以查看
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->belongsToStore($payment->store_id);
    }

    /**
     * 创建还款记录
     * 管理员或属于目标门店的用户可以创建
     * 
     * @param int|null $storeId 目标门店ID（从请求中获取）
     */
    public function create(User $user, ?int $storeId = null): bool
    {
        if ($storeId === null) {
            return false;
        }
        
        return $user->isAdmin() || $user->belongsToStore($storeId);
    }

    /**
     * 更新还款记录
     * 管理员或该门店的店长可以更新
     */
    public function update(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($payment->store_id);
    }

    /**
     * 删除还款记录
     * 管理员或该门店的店长可以删除
     */
    public function delete(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($payment->store_id);
    }

    /**
     * 分配还款到账单
     * 管理员或属于该门店的用户可以分配
     */
    public function allocate(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->belongsToStore($payment->store_id);
    }

    /**
     * 执行自动分配
     * 管理员或该门店的店长可以执行
     */
    public function autoAllocate(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($payment->store_id);
    }

    /**
     * 批量自动分配
     * 管理员或店长可以执行
     * 
     * @param int|null $storeId 可选的门店限制
     */
    public function batchAllocate(User $user, ?int $storeId = null): bool
    {
        if ($storeId === null) {
            return $user->isAdmin();
        }
        
        return $user->isAdmin() || $user->isManagerOfStore($storeId);
    }

    /**
     * 撤销分配
     * 管理员或该门店的店长可以撤销
     */
    public function revokeAllocation(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isManagerOfStore($payment->store_id);
    }

    /**
     * 检测差额
     * 管理员或属于该门店的用户可以检测
     */
    public function detectGap(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->belongsToStore($payment->store_id);
    }

    /**
     * 应用优惠减免
     * 管理员或属于该门店的用户可以应用（具体权限由 PaymentDiscountService 进一步验证）
     */
    public function applyDiscount(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->belongsToStore($payment->store_id);
    }

    /**
     * 查看优惠减免统计
     * 管理员或属于指定门店的用户可以查看
     * 
     * @param int|null $storeId 可选的门店ID
     */
    public function viewDiscountStatistics(User $user, ?int $storeId = null): bool
    {
        if ($storeId === null) {
            // 如果没有指定门店，用户必须至少属于一个门店
            return $user->isAdmin() || $user->stores()->exists();
        }
        
        return $user->isAdmin() || $user->belongsToStore($storeId);
    }
}
