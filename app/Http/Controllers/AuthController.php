<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Role;
use App\Models\Store;

/**
 * @group 认证管理
 *
 * 用户登录、登出和密码管理相关接口
 */
class AuthController extends ApiController
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * 用户登录
     *
     * 使用用户名/邮箱和密码登录系统，获取访问令牌。
     * 支持使用邮箱或用户名登录，系统会自动识别。
     *
     * @unauthenticated
     *
     * @bodyParam login string required 用户名或邮箱地址 Example: admin
     * @bodyParam password string required 用户密码 Example: password123
     *
     * @response 200 scenario="登录成功" {
     *   "success": true,
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "name": "管理员",
     *       "username": "admin",
     *       "email": "admin@example.com",
     *       "roles": ["admin"],
     *       "stores": [
     *         {
     *           "id": 1,
     *           "name": "总店",
     *           "code": "MAIN",
     *           "is_manager": true
     *         }
     *       ]
     *     },
     *     "token": "1|abcdefghijklmnopqrstuvwxyz123456"
     *   },
     *   "message": "登录成功"
     * }
     * @response 422 scenario="用户名或密码错误" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "login": ["用户名/邮箱或密码错误"]
     *   }
     * }
     * @response 422 scenario="参数验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "login": ["login 字段是必需的"],
     *     "password": ["password 字段是必需的"]
     *   }
     * }
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required',
        ]);

        // 判断登录字段是邮箱还是用户名
        $loginField = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::where($loginField, $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // 记录登录失败的审计日志
            if ($user) {
                $this->auditLogService->logLogin($user, false, '密码错误');
            }
            throw ValidationException::withMessages([
                'login' => ['用户名/邮箱或密码错误'],
            ]);
        }

        // 删除用户之前的所有令牌
        $user->tokens()->delete();

        // 创建新令牌
        $token = $user->createToken('api-token')->plainTextToken;

        // 加载用户关联数据
        $user->load(['roles', 'stores']);

        // 记录登录成功的审计日志
        $this->auditLogService->logLogin($user, true);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => $user->roles->pluck('slug')->toArray(),
                'stores' => $this->getUserStoresResponse($user),
            ],
            'token' => $token,
        ], '登录成功');
    }

    /**
     * 用户登出
     *
     * 销毁当前用户的访问令牌，使其失效。
     *
     * @response 200 scenario="登出成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "登出成功"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // 记录登出审计日志
        $this->auditLogService->logLogout($user);

        // 删除当前令牌
        $user->currentAccessToken()->delete();

        return $this->successResponse(null, '登出成功');
    }

    /**
     * 获取当前用户信息
     *
     * 获取当前登录用户的详细信息，包括角色和门店权限。
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "管理员",
     *     "username": "admin",
     *     "email": "admin@example.com",
     *     "email_verified_at": "2024-01-01T00:00:00.000000Z",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z",
     *     "roles": ["admin"],
     *     "stores": [
     *       {
     *         "id": 1,
     *         "name": "总店",
     *         "code": "MAIN",
     *         "is_manager": true
     *       }
     *     ]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function user(Request $request)
    {
        $user = $request->user();
        $user->load(['roles', 'stores']);

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'stores' => $this->getUserStoresResponse($user),
        ]);
    }

    /**
     * 用户注册
     *
     * 注册新用户账号，新用户默认获得店员(store_staff)角色。
     * 需要管理员权限才能注册新用户。
     *
     * @bodyParam name string required 用户真实姓名，最大255字符 Example: 张三
     * @bodyParam username string required 登录用户名，最大255字符，必须唯一 Example: zhangsan
     * @bodyParam email string required 邮箱地址，必须唯一 Example: zhangsan@example.com
     * @bodyParam password string required 密码，最少6位 Example: password123
     *
     * @response 200 scenario="注册成功" {
     *   "success": true,
     *   "data": {
     *     "user": {
     *       "id": 2,
     *       "name": "张三",
     *       "username": "zhangsan",
     *       "email": "zhangsan@example.com",
     *       "roles": ["store_staff"],
     *       "stores": []
     *     }
     *   },
     *   "message": "注册成功"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "username": ["用户名已被使用"],
     *     "email": ["邮箱已被注册"],
     *     "password": ["密码长度不能少于6位"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        // 创建用户
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 分配默认角色（店员）
        $staffRole = Role::where('slug', 'store_staff')->first();
        if ($staffRole) {
            $user->roles()->attach($staffRole->id);
        }

        // 加载用户关联数据
        $user->load(['roles', 'stores']);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => $user->roles->pluck('slug')->toArray(),
                'stores' => $user->stores->map(function ($store) {
                    return [
                        'id' => $store->id,
                        'name' => $store->name,
                        'code' => $store->code,
                        'is_manager' => $store->pivot->is_manager,
                    ];
                }),
            ],
        ], '注册成功');
    }

    /**
     * 修改密码
     *
     * 修改当前登录用户的密码。需要验证当前密码，新密码不能与当前密码相同。
     *
     * @bodyParam current_password string required 当前密码 Example: oldpassword123
     * @bodyParam new_password string required 新密码，最少6位 Example: newpassword456
     * @bodyParam new_password_confirmation string required 确认新密码 Example: newpassword456
     *
     * @response 200 scenario="修改成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "密码修改成功"
     * }
     * @response 422 scenario="当前密码错误" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "current_password": ["当前密码错误"]
     *   }
     * }
     * @response 422 scenario="新密码与当前密码相同" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "new_password": ["新密码不能与当前密码相同"]
     *   }
     * }
     * @response 422 scenario="新密码确认不一致" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "new_password": ["新密码与确认密码不一致"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function changePassword(Request $request)
    {
        // 验证请求参数
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
            'new_password_confirmation' => 'required|string',
        ], [
            'current_password.required' => '当前密码不能为空',
            'new_password.required' => '新密码不能为空',
            'new_password.min' => '新密码长度不能少于6位',
            'new_password.confirmed' => '新密码与确认密码不一致',
            'new_password_confirmation.required' => '确认密码不能为空',
        ]);

        $user = $request->user();

        // 验证当前密码是否正确
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['当前密码错误'],
            ]);
        }

        // 检查新密码是否与当前密码相同
        if (Hash::check($request->new_password, $user->password)) {
            throw ValidationException::withMessages([
                'new_password' => ['新密码不能与当前密码相同'],
            ]);
        }

        // 更新密码
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        // 强制下线：修改密码后清除该用户所有令牌，强制重新登录
        $user->tokens()->delete();

        return $this->successResponse(null, '密码修改成功');
    }

    /**
     * 获取用户可访问的门店列表响应
     *
     * 管理员用户返回系统中所有门店，其他用户返回其关联的门店
     *
     * @param User $user 用户实例
     * @return array 门店列表数组
     */
    private function getUserStoresResponse(User $user): array
    {
        // 如果是管理员，返回所有门店
        if ($user->isAdmin()) {
            return Store::all()->map(function ($store) {
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                    'is_manager' => true, // 管理员对所有门店都有管理权限
                ];
            })->toArray();
        }

        // 非管理员返回关联的门店
        return $user->stores->map(function ($store) {
            return [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'is_manager' => $store->pivot->is_manager ?? false,
            ];
        })->toArray();
    }
}
