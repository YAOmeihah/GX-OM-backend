<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerStoreStat;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group 门店管理
 *
 * 门店的创建、查询、更新和删除操作
 */
class StoreController extends ApiController
{
    /**
     * 获取门店列表
     *
     * 获取当前用户有权限访问的所有门店。管理员可以查看所有门店，
     * 其他用户只能查看自己所属的门店。
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "总店",
     *       "code": "MAIN",
     *       "address": "北京市朝阳区xxx路1号",
     *       "phone": "010-12345678",
     *       "description": "公司总部门店",
     *       "is_active": true,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     },
     *     {
     *       "id": 2,
     *       "name": "分店A",
     *       "code": "BRANCHA",
     *       "address": "上海市浦东新区xxx路100号",
     *       "phone": "021-87654321",
     *       "description": "上海分店",
     *       "is_active": true,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function index()
    {
        $user = Auth::user();

        if ($this->isAdmin()) {
            $stores = Store::all();
        } else {
            $stores = $user->stores;
        }

        return $this->successResponse($stores);
    }

    /**
     * 创建门店
     *
     * 创建新的门店记录。仅系统管理员可执行此操作。
     *
     * @bodyParam name string required 门店名称，最大255字符 Example: 新分店
     * @bodyParam code string required 门店编码，最大50字符，必须唯一，用于生成账单号等 Example: NEWBRANCH
     * @bodyParam address string 门店地址，最大255字符 Example: 广州市天河区xxx路200号
     * @bodyParam phone string 门店电话，最大20字符 Example: 020-11112222
     * @bodyParam description string 门店描述 Example: 广州新开分店
     * @bodyParam is_active boolean 是否启用，默认true Example: true
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 3,
     *     "name": "新分店",
     *     "code": "NEWBRANCH",
     *     "address": "广州市天河区xxx路200号",
     *     "phone": "020-11112222",
     *     "description": "广州新开分店",
     *     "is_active": true,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   },
     *   "message": "门店创建成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "code": ["门店编码已被使用"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function store(Request $request)
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:stores',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'wechat_pay_code_data' => 'nullable|string',
            'alipay_code_data' => 'nullable|string',
        ]);

        $store = Store::create($validated);

        return $this->successResponse($store, '门店创建成功', 201);
    }

    /**
     * 获取门店详情
     *
     * 获取指定门店的详细信息。管理员可查看任意门店，
     * 其他用户只能查看自己所属的门店。
     *
     * @urlParam id integer required 门店ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "总店",
     *     "code": "MAIN",
     *     "address": "北京市朝阳区xxx路1号",
     *     "phone": "010-12345678",
     *     "description": "公司总部门店",
     *     "is_active": true,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   }
     * }
     * @response 404 scenario="门店不存在" {
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
    public function show($id)
    {
        $store = Store::findOrFail($id);

        if (! $this->isAdmin() && ! $this->belongsToStore($store->id)) {
            return $this->errorResponse('权限不足', 403);
        }

        return $this->successResponse($store);
    }

    /**
     * 更新门店信息
     *
     * 更新指定门店的信息。需要系统管理员或该门店的店长权限。
     *
     * @urlParam id integer required 门店ID Example: 1
     *
     * @bodyParam name string 门店名称，最大255字符 Example: 总店（已更名）
     * @bodyParam code string 门店编码，最大50字符，必须唯一 Example: MAIN
     * @bodyParam address string 门店地址，最大255字符 Example: 北京市朝阳区新地址路1号
     * @bodyParam phone string 门店电话，最大20字符 Example: 010-88888888
     * @bodyParam description string 门店描述 Example: 公司总部门店（已搬迁）
     * @bodyParam is_active boolean 是否启用 Example: true
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "总店（已更名）",
     *     "code": "MAIN",
     *     "address": "北京市朝阳区新地址路1号",
     *     "phone": "010-88888888",
     *     "description": "公司总部门店（已搬迁）",
     *     "is_active": true,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-02T00:00:00.000000Z"
     *   },
     *   "message": "门店更新成功"
     * }
     * @response 404 scenario="门店不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "code": ["门店编码已被使用"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function update(Request $request, $id)
    {
        $store = Store::findOrFail($id);

        if (! $this->isAdmin() && ! $this->isManagerOfStore($store->id)) {
            return $this->errorResponse('需要系统管理员权限或店长权限', 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('stores')->ignore($store->id),
            ],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'wechat_pay_code_data' => 'nullable|string',
            'alipay_code_data' => 'nullable|string',
        ]);

        $store->update($validated);

        return $this->successResponse($store, '门店更新成功');
    }

    /**
     * 删除门店
     *
     * 删除指定门店。仅系统管理员可执行此操作。
     * 删除前检查关联数据，存在账单/还款/客户/统计时返回业务错误。
     *
     * @urlParam id integer required 门店ID Example: 1
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "门店删除成功"
     * }
     * @response 422 scenario="存在关联数据" {
     *   "success": false,
     *   "message": "该门店存在关联数据，无法删除"
     * }
     * @response 404 scenario="门店不存在" {
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
    public function destroy($id)
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('权限不足', 403);
        }

        $store = Store::findOrFail($id);

        // 检查关联数据
        $hasInvoices = Invoice::where('store_id', $store->id)->exists();
        $hasPayments = Payment::where('store_id', $store->id)->exists();
        $hasCustomers = Customer::where('store_id', $store->id)->exists();
        $hasStats = CustomerStoreStat::where('store_id', $store->id)->exists();

        if ($hasInvoices || $hasPayments || $hasCustomers || $hasStats) {
            return $this->errorResponse('该门店存在关联的账单、还款、客户或统计数据，无法删除', 422);
        }

        DB::transaction(function () use ($store) {
            $store->users()->detach();
            $store->delete();
        });

        return $this->successResponse(null, '门店删除成功');
    }

    /**
     * 获取门店用户列表
     *
     * 获取指定门店的所有关联用户（员工）。
     * 允许该门店的员工或管理员访问。
     *
     * @urlParam id integer required 门店ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "张三",
     *       "username": "zhangsan"
     *     }
     *   ]
     * }
     */
    public function users(Request $request, $id)
    {
        $store = Store::findOrFail($id);

        // 检查权限：管理员或该门店员工
        if (! $this->isAdmin() && ! $this->belongsToStore($store->id)) {
            return $this->errorResponse('权限不足', 403);
        }

        $users = $store->users()->select(['users.id', 'users.name', 'users.username', 'users.email'])->get();

        return $this->successResponse($users);
    }

    /**
     * 获取门店支付二维码数据
     *
     * @urlParam id integer required 门店ID Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "wechat_pay_code_data": "wxp://...",
     *     "alipay_code_data": "https://qr.alipay.com/..."
     *   }
     * }
     */
    public function paymentCodes(Request $request, $id)
    {
        $store = Store::findOrFail($id);

        // 权限检查：需管理员或该门店员工
        if (! $this->isAdmin() && ! $this->belongsToStore($store->id)) {
            return $this->errorResponse('权限不足', 403);
        }

        return $this->successResponse([
            'wechat_pay_code_data' => $store->wechat_pay_code_data,
            'alipay_code_data' => $store->alipay_code_data,
        ]);
    }
}
