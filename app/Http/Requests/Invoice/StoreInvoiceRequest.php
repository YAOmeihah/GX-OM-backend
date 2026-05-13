<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 创建账单请求验证
 * 
 * 将 InvoiceController::store() 中的验证逻辑抽离到此处
 */
class StoreInvoiceRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     */
    public function authorize(): bool
    {
        $storeId = $this->input('store_id');

        if (!$storeId) {
            return false;
        }

        $user = $this->user();
        return $user->isAdmin() || $user->belongsToStore($storeId);
    }

    /**
     * 获取应用于请求的验证规则
     */
    public function rules(): array
    {
        return [
            'store_id' => 'required|exists:stores,id',
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required_without:items|numeric|min:0.01',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string',
            'items' => 'nullable|array|min:1',
            'items.*.item_name' => 'nullable|string|max:255',
            'items.*.item_description' => 'nullable|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0.01',
            'items.*.sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 配置验证器实例
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // 验证：必须提供 amount 或 items 其中之一
            if (!$this->filled('amount') && !$this->filled('items')) {
                $validator->errors()->add('amount', '必须提供账单金额或明细项目');
            }
        });
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'store_id.required' => '门店ID不能为空',
            'store_id.exists' => '门店不存在',
            'customer_id.required' => '客户ID不能为空',
            'customer_id.exists' => '客户不存在',
            'amount.numeric' => '账单金额必须是数字',
            'amount.min' => '账单金额最小为0.01',
            'invoice_date.required' => '账单日期不能为空',
            'invoice_date.date' => '账单日期格式不正确',
            'due_date.date' => '到期日期格式不正确',
            'due_date.after_or_equal' => '到期日期必须晚于或等于账单日期',
            'items.array' => '明细项目格式不正确',
            'items.min' => '明细项目至少包含一项',
            'items.*.item_name.max' => '项目名称最大255字符',
            'items.*.quantity.required_with' => '项目数量不能为空',
            'items.*.quantity.min' => '项目数量最小为0.001',
            'items.*.unit_price.required_with' => '项目单价不能为空',
            'items.*.unit_price.min' => '项目单价最小为0.01',
        ];
    }

    /**
     * 处理授权失败的响应
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('您没有权限在此门店创建账单');
    }
}
