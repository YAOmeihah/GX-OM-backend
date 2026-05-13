<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiController extends Controller
{
    /**
     * 检查用户是否是管理员
     */
    protected function isAdmin(): bool
    {
        return Auth::check() && Auth::user()->hasRole('admin');
    }

    /**
     * 检查用户是否属于指定门店
     */
    protected function belongsToStore(int $storeId): bool
    {
        return Auth::check() && (
            $this->isAdmin() ||
            Auth::user()->stores()->where('store_id', $storeId)->exists()
        );
    }

    /**
     * 检查用户是否是指定门店的管理员
     */
    protected function isManagerOfStore(int $storeId): bool
    {
        return Auth::check() && (
            $this->isAdmin() ||
            (Auth::user()->hasRole('store_owner') && $this->belongsToStore($storeId))
        );
    }

    /**
     * 检查用户是否可以管理指定门店
     */
    protected function canManageStore(int $storeId): bool
    {
        return $this->isManagerOfStore($storeId);
    }

    /**
     * 检查用户是否可以访问指定门店
     */
    protected function canAccessStore(int $storeId): bool
    {
        return Auth::check() && (
            $this->isAdmin() ||
            $this->belongsToStore($storeId)
        );
    }

    /**
     * 检查用户是否是店长
     */
    protected function isStoreOwner(): bool
    {
        return Auth::check() && Auth::user()->hasRole('store_owner');
    }

    /**
     * 检查用户是否是店员
     */
    protected function isStoreStaff(): bool
    {
        return Auth::check() && Auth::user()->hasRole('store_staff');
    }

    /**
     * 获取用户有权限访问的门店ID列表
     */
    protected function getUserStoreIds(): array
    {
        if (!Auth::check()) {
            return [];
        }

        // 管理员可以访问所有门店
        if ($this->isAdmin()) {
            return \App\Models\Store::pluck('id')->toArray();
        }

        // 其他用户只能访问其关联的门店
        return Auth::user()->stores()->pluck('store_id')->toArray();
    }

    /**
     * 检查用户是否有权限访问任何门店
     */
    protected function hasStoreAccess(): bool
    {
        return !empty($this->getUserStoreIds());
    }

    /**
     * 为查询添加门店权限过滤
     */
    protected function addStoreFilter($query, string $storeColumn = 'store_id')
    {
        $storeIds = $this->getUserStoreIds();

        if (empty($storeIds)) {
            // 如果用户没有任何门店权限，返回空结果
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($storeColumn, $storeIds);
    }

    /**
     * 返回成功响应
     */
    protected function successResponse($data = null, string $message = null, int $code = 200): \Illuminate\Http\JsonResponse
    {
        $response = ['success' => true];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response, $code);
    }

    /**
     * 返回错误响应
     * 
     * @param string $message 错误消息
     * @param int $code HTTP 状态码
     * @param array|null $data 可选的附加数据
     */
    protected function errorResponse(string $message, int $code = 400, ?array $data = null): \Illuminate\Http\JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }
}