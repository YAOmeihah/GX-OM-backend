<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 权限管理
 *
 * 权限和角色权限的管理接口
 */
class PermissionController extends ApiController
{
    /**
     * 获取所有权限（按模块分组）
     *
     * 获取系统中所有31个权限点，按模块分组返回。
     * 用于权限管理页面展示和角色权限分配。
     *
     * **权限模块**:
     * - invoices: 账单管理 (4个权限)
     * - payments: 还款管理 (6个权限)
     * - customers: 客户管理 (4个权限)
     * - stores: 门店管理 (4个权限)
     * - users: 用户管理 (6个权限)
     * - dashboard: 仪表盘 (1个权限)
     * - reports: 报表管理 (2个权限)
     * - audit-logs: 审计日志 (1个权限)
     * - roles: 角色管理 (2个权限)
     * - settings: 系统设置 (1个权限)
     *
     * @authenticated
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "invoices": [
     *       {
     *         "id": 1,
     *         "name": "查看账单",
     *         "slug": "invoices.view",
     *         "module": "invoices",
     *         "description": "查看账单列表和详情"
     *       },
     *       {
     *         "id": 2,
     *         "name": "创建账单",
     *         "slug": "invoices.create",
     *         "module": "invoices",
     *         "description": "创建新账单"
     *       },
     *       {
     *         "id": 3,
     *         "name": "编辑账单",
     *         "slug": "invoices.update",
     *         "module": "invoices",
     *         "description": "编辑账单信息"
     *       },
     *       {
     *         "id": 4,
     *         "name": "删除账单",
     *         "slug": "invoices.delete",
     *         "module": "invoices",
     *         "description": "删除账单"
     *       }
     *     ],
     *     "payments": [
     *       {
     *         "id": 5,
     *         "name": "查看还款",
     *         "slug": "payments.view",
     *         "module": "payments",
     *         "description": "查看还款记录"
     *       }
     *     ]
     *   }
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     */
    public function index()
    {
        // 只有管理员能查看
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $permissions = Permission::all()->groupBy('module');

        return $this->successResponse($permissions);
    }

    /**
     * 获取角色的权限
     *
     * 获取指定角色被分配的所有权限。
     * 用于角色权限管理页面，显示某个角色当前拥有的权限。
     *
     * **系统角色**:
     * - admin (ID: 1): 管理员，拥有所有权限
     * - store_owner (ID: 2): 店长，拥有门店管理权限
     * - store_staff (ID: 3): 店员，拥有基础操作权限
     *
     * @authenticated
     *
     * @urlParam role integer required 角色ID Example: 2
     *
     * @response 200 scenario="获取店长权限" {
     *   "success": true,
     *   "data": {
     *     "role": {
     *       "id": 2,
     *       "name": "店长",
     *       "slug": "store_owner",
     *       "description": "门店负责人，管理本门店业务"
     *     },
     *     "permissions": [
     *       {
     *         "id": 1,
     *         "name": "查看账单",
     *         "slug": "invoices.view",
     *         "module": "invoices",
     *         "description": "查看账单列表和详情"
     *       },
     *       {
     *         "id": 2,
     *         "name": "创建账单",
     *         "slug": "invoices.create",
     *         "module": "invoices",
     *         "description": "创建新账单"
     *       }
     *     ]
     *   }
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 404 scenario="角色不存在" {
     *   "message": "No query results for model [App\\Models\\Role]."
     * }
     */
    public function getRolePermissions(Role $role)
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        return $this->successResponse([
            'role' => $role,
            'permissions' => $role->permissions,
        ]);
    }

    /**
     * 更新角色权限
     *
     * 批量更新指定角色的权限分配。
     * 会完全替换角色的现有权限，未在列表中的权限将被移除。
     *
     * **操作说明**:
     * - 使用权限ID数组进行批量分配
     * - 支持同时分配多个权限
     * - 自动验证权限ID的有效性
     * - 更新成功后返回新的权限列表
     *
     * **注意事项**:
     * - 管理员角色(admin)默认拥有所有权限，无需手动分配
     * - 分配的权限必须存在于系统中
     * - 此操作会完全替换原有权限，请确保包含所有需要的权限
     *
     * @authenticated
     *
     * @urlParam role integer required 角色ID Example: 2
     *
     * @bodyParam permissions array required 权限ID数组 Example: [1, 2, 3, 5, 7]
     * @bodyParam permissions.* integer required 权限ID，必须是系统中存在的权限 Example: 1
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 2,
     *     "name": "店长",
     *     "slug": "store_owner",
     *     "description": "门店负责人，管理本门店业务",
     *     "permissions": [
     *       {
     *         "id": 1,
     *         "name": "查看账单",
     *         "slug": "invoices.view",
     *         "module": "invoices"
     *       },
     *       {
     *         "id": 2,
     *         "name": "创建账单",
     *         "slug": "invoices.create",
     *         "module": "invoices"
     *       },
     *       {
     *         "id": 3,
     *         "name": "编辑账单",
     *         "slug": "invoices.update",
     *         "module": "invoices"
     *       }
     *     ]
     *   },
     *   "message": "权限更新成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="参数验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "permissions": ["permissions 字段是必需的"],
     *     "permissions.0": ["所选的 permissions.0 无效"]
     *   }
     * }
     * @response 404 scenario="角色不存在" {
     *   "message": "No query results for model [App\\Models\\Role]."
     * }
     */
    public function updateRolePermissions(Request $request, Role $role)
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->permissions()->sync($validated['permissions']);

        return $this->successResponse(
            $role->load('permissions'),
            '权限更新成功'
        );
    }

    /**
     * 获取当前用户权限列表
     *
     * 获取当前登录用户的所有权限标识和角色标识。
     * 前端使用此接口获取权限列表，用于控制页面按钮、菜单等UI元素的显示。
     * 管理员(admin)自动拥有所有权限。
     *
     * **使用场景**:
     * - 用户登录后自动调用，获取权限列表
     * - 页面刷新时重新获取权限，确保权限控制正常
     * - 权限更新后可手动调用刷新权限
     *
     * **注意事项**:
     * - 前端权限检查仅用于UI控制，真正的权限验证在后端进行
     * - 管理员角色返回所有31个权限点
     * - 其他角色返回其被分配的权限点
     *
     * @authenticated
     *
     * @response 200 scenario="店员用户" {
     *   "success": true,
     *   "data": {
     *     "permissions": [
     *       "invoices.view",
     *       "invoices.create",
     *       "payments.view",
     *       "payments.create",
     *       "customers.view"
     *     ],
     *     "roles": [
     *       "store_staff"
     *     ]
     *   }
     * }
     * @response 200 scenario="店长用户" {
     *   "success": true,
     *   "data": {
     *     "permissions": [
     *       "invoices.view",
     *       "invoices.create",
     *       "invoices.update",
     *       "invoices.delete",
     *       "payments.view",
     *       "payments.create",
     *       "payments.allocate",
     *       "payments.revoke",
     *       "payments.discount",
     *       "payments.delete",
     *       "customers.view",
     *       "customers.create",
     *       "customers.update",
     *       "customers.delete",
     *       "dashboard.view",
     *       "reports.view",
     *       "reports.export"
     *     ],
     *     "roles": [
     *       "store_owner"
     *     ]
     *   }
     * }
     * @response 200 scenario="管理员用户" {
     *   "success": true,
     *   "data": {
     *     "permissions": [
     *       "invoices.view",
     *       "invoices.create",
     *       "invoices.update",
     *       "invoices.delete",
     *       "payments.view",
     *       "payments.create",
     *       "payments.allocate",
     *       "payments.revoke",
     *       "payments.discount",
     *       "payments.delete",
     *       "customers.view",
     *       "customers.create",
     *       "customers.update",
     *       "customers.delete",
     *       "stores.view",
     *       "stores.create",
     *       "stores.update",
     *       "stores.delete",
     *       "users.view",
     *       "users.create",
     *       "users.update",
     *       "users.delete",
     *       "users.assign-roles",
     *       "users.assign-stores",
     *       "dashboard.view",
     *       "reports.view",
     *       "reports.export",
     *       "audit-logs.view",
     *       "roles.view",
     *       "roles.update",
     *       "settings.manage"
     *     ],
     *     "roles": [
     *       "admin"
     *     ]
     *   }
     * }
     * @response 401 scenario="未登录" {
     *   "success": false,
     *   "message": "未认证用户",
     *   "error_code": "UNAUTHENTICATED"
     * }
     */
    public function myPermissions()
    {
        $user = Auth::user();

        return $this->successResponse([
            'permissions' => $user->getPermissionsList(),
            'roles' => $user->getRolesList(),
        ]);
    }

    /**
     * 获取所有模块列表
     *
     * 获取系统中所有权限模块的唯一标识列表。
     * 用于权限管理界面的模块分类展示。
     *
     * **返回的模块**:
     * - invoices: 账单管理
     * - payments: 还款管理
     * - customers: 客户管理
     * - stores: 门店管理
     * - users: 用户管理
     * - dashboard: 仪表盘
     * - reports: 报表管理
     * - audit-logs: 审计日志
     * - roles: 角色管理
     * - settings: 系统设置
     *
     * @authenticated
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     "invoices",
     *     "payments",
     *     "customers",
     *     "stores",
     *     "users",
     *     "dashboard",
     *     "reports",
     *     "audit-logs",
     *     "roles",
     *     "settings"
     *   ]
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     */
    public function getModules()
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $modules = Permission::select('module')
            ->distinct()
            ->pluck('module');

        return $this->successResponse($modules);
    }
}
