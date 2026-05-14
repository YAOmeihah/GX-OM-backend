<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 权限检查中间件
 *
 * 用法：
 * Route::middleware('permission:invoices.create')->post('/invoices', [InvoiceController::class, 'store']);
 * Route::middleware('permission:invoices.view,invoices.create')->get('/invoices', [InvoiceController::class, 'index']); // 任一权限
 */
class CheckPermission
{
    /**
     * 处理传入请求
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permissions  权限标识，多个权限用逗号分隔（任一满足即可）
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => '未认证用户',
            ], 401);
        }

        // 支持多个权限，用逗号分隔（任一满足即可）
        $permissionArray = explode(',', $permissions);

        if (! $request->user()->hasAnyPermission($permissionArray)) {
            return response()->json([
                'success' => false,
                'message' => '权限不足',
                'required_permissions' => $permissionArray,
            ], 403);
        }

        return $next($request);
    }
}
