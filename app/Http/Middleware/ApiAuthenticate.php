<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticate extends Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string[]  ...$guards
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function handle($request, Closure $next, ...$guards)
    {
        try {
            $this->authenticate($request, $guards);
        } catch (AuthenticationException $e) {
            return $this->handleUnauthenticated($request, $e);
        }

        return $next($request);
    }

    /**
     * Handle unauthenticated requests for API.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleUnauthenticated(Request $request, AuthenticationException $exception): Response
    {
        return response()->json([
            'success' => false,
            'message' => '认证失败，请提供有效的访问令牌',
            'error_code' => 'UNAUTHENTICATED',
            'error_details' => [
                'type' => 'authentication_required',
                'description' => '此接口需要认证，请在请求头中包含有效的Bearer令牌',
                'header_format' => 'Authorization: Bearer {your_token}',
                'login_endpoint' => url('/api/login'),
                'how_to_get_token' => '请先调用登录接口获取访问令牌'
            ],
            'timestamp' => now()->toISOString()
        ], 401, [
            'Content-Type' => 'application/json',
            'WWW-Authenticate' => 'Bearer realm="API"'
        ]);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * 
     * 对于API中间件，我们不进行重定向，而是返回JSON响应
     */
    protected function redirectTo(Request $request): ?string
    {
        // 对于API请求，不进行重定向
        return null;
    }
}
