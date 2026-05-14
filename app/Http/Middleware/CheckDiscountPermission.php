<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckDiscountPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $discountType = null): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => '未认证用户',
            ], 401);
        }

        // 获取门店ID（从路由参数或请求参数中）
        $storeId = $request->route('store_id') ?? $request->input('store_id');

        // 如果是还款相关的优惠减免，从还款记录中获取门店ID
        if (! $storeId && $request->route('id')) {
            $paymentId = $request->route('id');
            $payment = \App\Models\Payment::find($paymentId);
            if ($payment) {
                $storeId = $payment->store_id;
            }
        }

        // 系统管理员拥有所有权限
        if ($user->hasRole('admin')) {
            return $next($request);
        }

        // 检查用户是否属于该门店
        if ($storeId && ! $user->stores()->where('store_id', $storeId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => '您没有权限访问该门店的数据',
            ], 403);
        }

        // 根据折扣类型检查权限
        if ($discountType) {
            $hasPermission = $this->checkDiscountTypePermission($user, $discountType, $storeId);

            if (! $hasPermission) {
                return response()->json([
                    'success' => false,
                    'message' => "您没有权限进行{$discountType}类型的优惠减免",
                ], 403);
            }
        } else {
            // 通用的优惠减免权限检查
            if (! $this->hasGeneralDiscountPermission($user, $storeId)) {
                return response()->json([
                    'success' => false,
                    'message' => '您没有权限进行优惠减免操作',
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * 检查特定折扣类型的权限
     */
    private function checkDiscountTypePermission($user, string $discountType, ?int $storeId = null): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        $allowedRoles = $discountConfig['approval_roles'] ?? [];

        // 检查用户是否有允许的角色
        foreach ($allowedRoles as $role) {
            if ($user->hasRole($role)) {
                // 如果是店长或店员，需要验证是否属于该门店
                if (in_array($role, ['store_owner', 'store_staff']) && $storeId) {
                    return $user->stores()->where('store_id', $storeId)->exists();
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 检查通用的优惠减免权限
     */
    private function hasGeneralDiscountPermission($user, ?int $storeId = null): bool
    {
        // 店长可以进行优惠减免
        if ($user->hasRole('store_owner')) {
            if ($storeId) {
                return $user->stores()->where('store_id', $storeId)->exists();
            }

            return true;
        }

        // 店员在配置允许的情况下可以进行小额优惠减免
        if ($user->hasRole('store_staff')) {
            $staffCanDiscount = config('payment.discount_types.discount.approval_roles', []);
            if (in_array('store_staff', $staffCanDiscount)) {
                if ($storeId) {
                    return $user->stores()->where('store_id', $storeId)->exists();
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 检查优惠减免金额是否在用户权限范围内
     */
    public static function checkDiscountAmount($user, string $discountType, float $amount): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        $maxAmount = $discountConfig['max_amount'] ?? 0;

        // 系统管理员不受金额限制
        if ($user->hasRole('admin')) {
            return true;
        }

        // 店长有更高的金额限制
        if ($user->hasRole('store_owner')) {
            return $amount <= $maxAmount;
        }

        // 店员只能进行小额优惠减免
        if ($user->hasRole('store_staff')) {
            $staffMaxAmount = min($maxAmount, config('payment.auto_discount.max_amount', 100));

            return $amount <= $staffMaxAmount;
        }

        return false;
    }

    /**
     * 检查是否需要额外审批
     */
    public static function requiresApproval(string $discountType, float $amount): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return true;
        }

        // 检查是否需要审批
        if ($discountConfig['requires_approval'] ?? false) {
            return true;
        }

        // 检查金额是否超过自动审批限制
        $autoApprovalLimit = config('payment.auto_discount.max_amount', 100);

        return $amount > $autoApprovalLimit;
    }
}
