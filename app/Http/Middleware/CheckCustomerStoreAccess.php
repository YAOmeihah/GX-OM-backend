<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckCustomerStoreAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => '未认证用户',
            ], 401);
        }

        // 系统管理员拥有所有权限
        if ($user->hasRole('admin')) {
            return $next($request);
        }

        // 获取用户有权限访问的门店ID列表
        $userStoreIds = $user->stores()->pluck('store_id')->toArray();

        if (empty($userStoreIds)) {
            return response()->json([
                'success' => false,
                'message' => '您没有权限访问任何门店的数据',
            ], 403);
        }

        // 如果请求中指定了门店ID，验证权限
        $requestedStoreId = $request->input('store_id');
        if ($requestedStoreId && ! in_array($requestedStoreId, $userStoreIds)) {
            return response()->json([
                'success' => false,
                'message' => '您没有权限访问该门店的数据',
            ], 403);
        }

        // 将用户有权限的门店ID列表添加到请求中，供控制器使用
        $request->merge(['_user_store_ids' => $userStoreIds]);

        return $next($request);
    }
}
