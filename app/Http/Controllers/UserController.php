<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Store;

/**
 * @group 用户管理
 *
 * 系统用户的查询、角色分配和门店分配（仅管理员）
 */
class UserController extends ApiController
{
    /**
     * 获取用户列表
     *
     * 获取系统所有用户的分页列表，支持按关键词搜索和角色筛选。
     * 仅系统管理员可以访问此接口。
     *
     * @queryParam search string 搜索关键词，可搜索姓名、用户名、邮箱 Example: admin
     * @queryParam role string 按角色筛选，可选值：admin、store_owner、store_staff Example: store_owner
     * @queryParam per_page integer 每页显示数量，默认15 Example: 15
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "name": "管理员",
     *         "username": "admin",
     *         "email": "admin@example.com",
     *         "created_at": "2024-01-01T00:00:00.000000Z",
     *         "roles": [
     *           {
     *             "id": 1,
     *             "name": "系统管理员",
     *             "slug": "admin"
     *           }
     *         ],
     *         "permissions": {
     *           "is_admin": true,
     *           "is_store_owner": false,
     *           "is_store_staff": false
     *         },
     *         "stores": [
     *           {
     *             "id": 1,
     *             "name": "总店",
     *             "code": "MAIN"
     *           }
     *         ]
     *       }
     *     ],
     *     "first_page_url": "http://localhost/api/users?page=1",
     *     "from": 1,
     *     "last_page": 1,
     *     "last_page_url": "http://localhost/api/users?page=1",
     *     "next_page_url": null,
     *     "path": "http://localhost/api/users",
     *     "per_page": 15,
     *     "prev_page_url": null,
     *     "to": 1,
     *     "total": 1
     *   }
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */

    public function index(Request $request)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $query = User::with(['roles', 'stores']);

        // 搜索功能
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 角色筛选
        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('slug', $request->role);
            });
        }

        $users = $query->paginate($request->get('per_page', 15));

        // 格式化响应数据
        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                    ];
                }),
                'permissions' => [
                    'is_admin' => $user->hasRole('admin'),
                    'is_store_owner' => $user->hasRole('store_owner'),
                    'is_store_staff' => $user->hasRole('store_staff'),
                ],
                'stores' => $user->stores->map(function ($store) {
                    return [
                        'id' => $store->id,
                        'name' => $store->name,
                        'code' => $store->code,
                    ];
                }),
            ];
        });

        return $this->successResponse($users);
    }

    /**
     * 创建新用户
     *
     * 创建一个新的系统用户，并分配初始角色和门店权限。
     * 仅系统管理员可以访问此接口。
     *
     * @bodyParam name string required 姓名 Example: 张三
     * @bodyParam username string required 用户名 Example: zhangsan
     * @bodyParam email string required 邮箱 Example: zhangsan@example.com
     * @bodyParam password string required 密码（至少6位） Example: password123
     * @bodyParam password_confirmation string required 确认密码 Example: password123
     * @bodyParam role_ids array required 角色ID列表 Example: [2]
     * @bodyParam store_ids array optional 门店ID列表 Example: [1]
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 5,
     *     "name": "张三",
     *     "username": "zhangsan",
     *     "email": "zhangsan@example.com",
     *     "created_at": "2024-01-01 10:00:00",
     *     "roles": [...],
     *     "stores": [...]
     *   },
     *   "message": "用户创建成功"
     * }
     */
    public function store(Request $request)
    {
        // 验证管理员权限
        if (!$this->isAdmin()) {
            return $this->errorResponse('仅管理员可创建用户', 403);
        }

        // 验证输入
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'nullable|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
        ]);

        // 如果未提供邮箱，自动生成一个
        $email = $validated['email'] ?? ($validated['username'] . '@system.local');

        // 创建用户
        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
        ]);

        // 分配角色和门店
        $user->roles()->sync($validated['role_ids']);
        if (!empty($validated['store_ids'])) {
            $user->stores()->sync($validated['store_ids']);
        }

        return $this->successResponse($user->load(['roles', 'stores']), '用户创建成功', 201);
    }

    /**
     * 获取用户详情
     *
     * 获取指定用户的详细信息，包括角色和门店权限。
     * 仅系统管理员可以访问此接口。
     *
     * @urlParam user_id integer required 用户ID Example: 1
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
     *     "roles": [
     *       {
     *         "id": 1,
     *         "name": "系统管理员",
     *         "slug": "admin"
     *       }
     *     ],
     *     "permissions": {
     *       "is_admin": true,
     *       "is_store_owner": false,
     *       "is_store_staff": false
     *     },
     *     "stores": [
     *       {
     *         "id": 1,
     *         "name": "总店",
     *         "code": "MAIN"
     *       }
     *     ]
     *   }
     * }
     * @response 404 scenario="用户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function show(Request $request, User $user)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $user->load(['roles', 'stores']);

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $user->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ];
            }),
            'permissions' => [
                'is_admin' => $user->hasRole('admin'),
                'is_store_owner' => $user->hasRole('store_owner'),
                'is_store_staff' => $user->hasRole('store_staff'),
            ],
            'stores' => $user->stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                ];
            }),
        ]);
    }

    /**
     * 更新用户详情
     *
     * 更新用户的基本信息、密码和角色。仅系统管理员可调用。
     * 若不修改密码，请留空密码字段。
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "message": "用户更新成功"
     * }
     */
    public function update(Request $request, User $user)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6|confirmed',
            'role_ids' => 'sometimes|array',
            'role_ids.*' => 'exists:roles,id',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
        ]);

        // 更新基本信息
        $user->name = $validated['name'];
        $user->username = $validated['username'];
        // 如果未提供邮箱，自动生成一个 (保持与store方法一致的逻辑)
        $user->email = !empty($validated['email']) ? $validated['email'] : ($validated['username'] . '@system.local');

        // 如果提供了密码，则更新
        if (!empty($validated['password'])) {
            $user->password = \Illuminate\Support\Facades\Hash::make($validated['password']);
            // 强制下线：修改密码后清除该用户所有令牌
            $user->tokens()->delete();
        }

        $user->save();

        // 仅在明确提供 role_ids 时更新角色
        if (isset($validated['role_ids'])) {
            // 防止删除自己的管理员角色
            if ($user->id === $request->user()->id) {
                $adminRole = Role::where('slug', 'admin')->first();
                if ($adminRole && !in_array($adminRole->id, $validated['role_ids'])) {
                    return $this->errorResponse('不能删除自己的管理员角色', 400);
                }
            }
            $user->roles()->sync($validated['role_ids']);
        }

        // 仅在明确提供 store_ids 时更新门店 (如果前端传了)
        // 注意：前端编辑逻辑中 store_ids 不是必须的，如果没改可能不传?
        // 对应 store 方法逻辑，如果传了就 sync
        if (array_key_exists('store_ids', $validated)) { // 使用 array_key_exists 检查是否包含该键
            $user->stores()->sync($validated['store_ids'] ?? []);
        }

        return $this->successResponse($user->load(['roles', 'stores']), '用户更新成功');
    }

    /**
     * 更新用户角色
     *
     * 更新指定用户的角色。仅系统管理员可以执行此操作。
     * 不能删除自己的管理员角色。
     *
     * @urlParam user_id integer required 用户ID Example: 2
     *
     * @bodyParam role_ids array required 角色ID列表 Example: [2, 3]
     * @bodyParam role_ids.* integer required 角色ID，必须是已存在的角色 Example: 2
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 2,
     *     "name": "张三",
     *     "username": "zhangsan",
     *     "email": "zhangsan@example.com",
     *     "roles": [
     *       {
     *         "id": 2,
     *         "name": "店长",
     *         "slug": "store_owner"
     *       }
     *     ],
     *     "permissions": {
     *       "is_admin": false,
     *       "is_store_owner": true,
     *       "is_store_staff": false
     *     },
     *     "stores": [
     *       {
     *         "id": 1,
     *         "name": "总店",
     *         "code": "MAIN"
     *       }
     *     ]
     *   },
     *   "message": "用户角色更新成功"
     * }
     * @response 400 scenario="删除自己的管理员角色" {
     *   "success": false,
     *   "message": "不能删除自己的管理员角色"
     * }
     * @response 404 scenario="用户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "role_ids": ["角色ID列表不能为空"],
     *     "role_ids.0": ["角色不存在"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function updateRoles(Request $request, User $user)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $request->validate([
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        // 防止删除自己的管理员角色
        if ($user->id === $request->user()->id) {
            $adminRole = Role::where('slug', 'admin')->first();
            if ($adminRole && !in_array($adminRole->id, $request->role_ids)) {
                return $this->errorResponse('不能删除自己的管理员角色', 400);
            }
        }

        // 同步角色
        $user->roles()->sync($request->role_ids);

        $user->load(['roles', 'stores']);

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ];
            }),
            'permissions' => [
                'is_admin' => $user->hasRole('admin'),
                'is_store_owner' => $user->hasRole('store_owner'),
                'is_store_staff' => $user->hasRole('store_staff'),
            ],
            'stores' => $user->stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                ];
            }),
        ], '用户角色更新成功');
    }

    /**
     * 更新用户门店权限
     *
     * 更新指定用户所属的门店列表。仅系统管理员可以执行此操作。
     *
     * @urlParam user_id integer required 用户ID Example: 2
     *
     * @bodyParam stores array required 门店ID列表 Example: [1, 2]
     * @bodyParam stores.* integer required 门店ID，必须是已存在的门店 Example: 1
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 2,
     *     "name": "张三",
     *     "username": "zhangsan",
     *     "email": "zhangsan@example.com",
     *     "roles": [
     *       {
     *         "id": 2,
     *         "name": "店长",
     *         "slug": "store_owner"
     *       }
     *     ],
     *     "stores": [
     *       {
     *         "id": 1,
     *         "name": "总店",
     *         "code": "MAIN"
     *       },
     *       {
     *         "id": 2,
     *         "name": "分店A",
     *         "code": "BRANCHA"
     *       }
     *     ]
     *   },
     *   "message": "用户门店权限更新成功"
     * }
     * @response 404 scenario="用户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "stores": ["门店列表不能为空"],
     *     "stores.0": ["门店不存在"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function updateStores(Request $request, User $user)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $request->validate([
            'stores' => 'required|array',
            'stores.*' => 'required|exists:stores,id',
        ]);

        // 同步门店关系（不再需要is_manager字段）
        $user->stores()->sync($request->stores);

        $user->load(['roles', 'stores']);

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ];
            }),
            'stores' => $user->stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                ];
            }),
        ], '用户门店权限更新成功');
    }

    /**
     * 获取角色列表
     *
     * 获取系统所有可用的角色列表。仅系统管理员可以访问此接口。
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "系统管理员",
     *       "slug": "admin",
     *       "description": "拥有系统所有权限",
     *       "is_system": true
     *     },
     *     {
     *       "id": 2,
     *       "name": "店长",
     *       "slug": "store_owner",
     *       "description": "管理所属门店",
     *       "is_system": true
     *     },
     *     {
     *       "id": 3,
     *       "name": "店员",
     *       "slug": "store_staff",
     *       "description": "基础操作权限",
     *       "is_system": true
     *     }
     *   ]
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function getRoles(Request $request)
    {
        // 检查权限
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $roles = Role::all();

        return $this->successResponse($roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_system' => $role->is_system,
            ];
        }));
    }
}
