<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;

/**
 * @group 账单明细
 *
 * 账单明细项目的增删改查操作
 */
class InvoiceItemController extends ApiController
{
    /**
     * 获取账单明细列表
     *
     * 获取指定账单的所有明细项目，按排序号排列。
     *
     * @urlParam invoice integer required 账单ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "invoice_id": 1,
     *       "item_name": "商品A",
     *       "item_description": "优质商品",
     *       "quantity": "2.000",
     *       "unit_price": "500.00",
     *       "subtotal": "1000.00",
     *       "sort_order": 0,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     },
     *     {
     *       "id": 2,
     *       "invoice_id": 1,
     *       "item_name": "商品B",
     *       "item_description": "普通商品",
     *       "quantity": "5.000",
     *       "unit_price": "100.00",
     *       "subtotal": "500.00",
     *       "sort_order": 1,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * @response 404 scenario="账单不存在" {
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
    public function index($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        // 验证用户是否有权限查看该账单
        if (! $this->isAdmin() && ! $this->belongsToStore($invoice->store_id)) {
            return $this->errorResponse('权限不足', 403);
        }

        $items = $invoice->items()->orderBy('sort_order')->get();

        return $this->successResponse($items);
    }

    /**
     * 添加账单明细项
     *
     * 为指定账单添加新的明细项目。需要系统管理员或该门店店长权限。
     * 如果账单已有付款记录则无法添加明细。
     *
     * @urlParam invoice integer required 账单ID Example: 1
     *
     * @bodyParam item_name string 项目名称，最大255字符 Example: 商品C
     * @bodyParam item_description string 项目描述 Example: 新增商品
     * @bodyParam quantity number required 数量，最小0.001 Example: 3
     * @bodyParam unit_price number required 单价，最小0.01 Example: 200.00
     * @bodyParam sort_order integer 排序号，最小0，不填则自动排到最后 Example: 2
     *
     * @response 201 scenario="添加成功" {
     *   "success": true,
     *   "data": {
     *     "id": 3,
     *     "invoice_id": 1,
     *     "item_name": "商品C",
     *     "item_description": "新增商品",
     *     "quantity": "3.000",
     *     "unit_price": "200.00",
     *     "subtotal": "600.00",
     *     "sort_order": 2,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   },
     *   "message": "明细项添加成功"
     * }
     * @response 404 scenario="账单不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="账单已有付款" {
     *   "success": false,
     *   "message": "该账单已有付款记录，无法修改明细"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "quantity": ["数量不能为空"],
     *     "unit_price": ["单价不能为空"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function store(Request $request, $invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        // 验证用户是否有权限修改该账单
        if (! $this->isAdmin() && ! $this->isManagerOfStore($invoice->store_id)) {
            return $this->errorResponse('需要系统管理员权限或店长权限', 403);
        }

        // 如果账单已经有付款，则不能添加明细
        if ($invoice->paid_amount > 0) {
            return $this->errorResponse('该账单已有付款记录，无法修改明细', 422);
        }

        $validated = $request->validate([
            'item_name' => 'nullable|string|max:255',
            'item_description' => 'nullable|string',
            'quantity' => 'required|numeric|min:0.001',
            'unit_price' => 'required|numeric|min:0.01',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $item = $invoice->items()->create([
            'item_name' => $validated['item_name'],
            'item_description' => $validated['item_description'] ?? null,
            'quantity' => $validated['quantity'],
            'unit_price' => $validated['unit_price'],
            'sort_order' => $validated['sort_order'] ?? $invoice->items()->max('sort_order') + 1,
        ]);

        return $this->successResponse($item, '明细项添加成功', 201);
    }

    /**
     * 更新账单明细项
     *
     * 更新指定的明细项目。需要系统管理员或该门店店长权限。
     * 如果账单已有付款记录则无法修改明细。
     *
     * @urlParam item integer required 明细项ID Example: 1
     *
     * @bodyParam item_name string 项目名称，最大255字符 Example: 商品A（已更新）
     * @bodyParam item_description string 项目描述 Example: 优质商品（更新描述）
     * @bodyParam quantity number 数量，最小0.001 Example: 5
     * @bodyParam unit_price number 单价，最小0.01 Example: 450.00
     * @bodyParam sort_order integer 排序号，最小0 Example: 0
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "invoice_id": 1,
     *     "item_name": "商品A（已更新）",
     *     "item_description": "优质商品（更新描述）",
     *     "quantity": "5.000",
     *     "unit_price": "450.00",
     *     "subtotal": "2250.00",
     *     "sort_order": 0,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-02T00:00:00.000000Z"
     *   },
     *   "message": "明细项更新成功"
     * }
     * @response 404 scenario="明细项不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="账单已有付款" {
     *   "success": false,
     *   "message": "该账单已有付款记录，无法修改明细"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function update(Request $request, $itemId)
    {
        $item = InvoiceItem::findOrFail($itemId);
        $invoice = $item->invoice;

        // 验证用户是否有权限修改该账单
        if (! $this->isAdmin() && ! $this->isManagerOfStore($invoice->store_id)) {
            return $this->errorResponse('需要系统管理员权限或店长权限', 403);
        }

        // 如果账单已经有付款，则不能修改明细
        if ($invoice->paid_amount > 0) {
            return $this->errorResponse('该账单已有付款记录，无法修改明细', 422);
        }

        $validated = $request->validate([
            'item_name' => 'sometimes|nullable|string|max:255',
            'item_description' => 'nullable|string',
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'unit_price' => 'sometimes|required|numeric|min:0.01',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $item->update($validated);

        return $this->successResponse($item, '明细项更新成功');
    }

    /**
     * 删除账单明细项
     *
     * 删除指定的明细项目。需要系统管理员或该门店店长权限。
     * 如果账单已有付款记录则无法删除明细。账单至少需要保留一个明细项。
     *
     * @urlParam item integer required 明细项ID Example: 2
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "明细项删除成功"
     * }
     * @response 404 scenario="明细项不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="账单已有付款" {
     *   "success": false,
     *   "message": "该账单已有付款记录，无法删除明细"
     * }
     * @response 422 scenario="最后一个明细项" {
     *   "success": false,
     *   "message": "账单至少需要保留一个明细项"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function destroy($itemId)
    {
        $item = InvoiceItem::findOrFail($itemId);
        $invoice = $item->invoice;

        // 验证用户是否有权限修改该账单
        if (! $this->isAdmin() && ! $this->isManagerOfStore($invoice->store_id)) {
            return $this->errorResponse('需要系统管理员权限或店长权限', 403);
        }

        // 如果账单已经有付款，则不能删除明细
        if ($invoice->paid_amount > 0) {
            return $this->errorResponse('该账单已有付款记录，无法删除明细', 422);
        }

        // 检查是否是最后一个明细项
        if ($invoice->items()->count() <= 1) {
            return $this->errorResponse('账单至少需要保留一个明细项', 422);
        }

        $item->delete();

        return $this->successResponse(null, '明细项删除成功');
    }
}
