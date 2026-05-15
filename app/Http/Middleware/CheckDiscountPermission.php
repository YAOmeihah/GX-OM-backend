<?php

namespace App\Http\Middleware;

use App\Services\DiscountPermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckDiscountPermission
{
    public function __construct(private readonly DiscountPermissionService $discountPermissions) {}

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

        $storeId = $storeId ? (int) $storeId : null;

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
            $hasPermission = $this->discountPermissions->hasDiscountTypePermission($user, $discountType, $storeId);

            if (! $hasPermission) {
                return response()->json([
                    'success' => false,
                    'message' => "您没有权限进行{$discountType}类型的优惠减免",
                ], 403);
            }
        } else {
            // 通用的优惠减免权限检查
            if (! $this->discountPermissions->hasGeneralDiscountPermission($user, $storeId)) {
                return response()->json([
                    'success' => false,
                    'message' => '您没有权限进行优惠减免操作',
                ], 403);
            }
        }

        return $next($request);
    }
}
