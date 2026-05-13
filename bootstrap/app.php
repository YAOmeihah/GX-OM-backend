<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\ApiAuthenticate::class,
            'discount.permission' => \App\Http\Middleware\CheckDiscountPermission::class,
            'customer.store.access' => \App\Http\Middleware\CheckCustomerStoreAccess::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 自定义认证异常处理
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // 对于API请求或期望JSON响应的请求，返回JSON格式的401响应
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '未认证用户，请先登录',
                    'error_code' => 'UNAUTHENTICATED',
                    'login_url' => url('/api/login')
                ], 401);
            }

            // 对于Web请求，重定向到登录页面
            return redirect()->guest(route('login'));
        });
    })->create();
