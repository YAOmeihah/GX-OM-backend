<?php

namespace App\Http\Requests\Invoice;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新账单请求验证
 * 
 * 将 InvoiceController::update() 中的验证逻辑抽离到此处
 */
class UpdateInvoiceRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     */
    public function authorize(): bool
    {
        $invoice = $this->getInvoice();

        if (!$invoice) {
            return false;
        }

        $user = $this->user();
        return $user->isAdmin() || $user->isManagerOfStore($invoice->store_id);
    }

    /**
     * 获取应用于请求的验证规则
     */
    public function rules(): array
    {
        $invoice = $this->getInvoice();

        // 如果账单已有付款，则只能更新描述
        if ($invoice && $invoice->paid_amount > 0) {
            return [
                'description' => 'nullable|string',
            ];
        }

        return [
            'customer_id' => 'sometimes|exists:customers,id',
            'amount' => 'nullable|numeric|min:0.01',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string',
            'items' => 'nullable|array|min:1',
            'items.*.line_uid' => 'nullable|string|size:36|distinct',
            'items.*.item_name' => 'nullable|string|max:255',
            'items.*.item_description' => 'nullable|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0.01',
            'items.*.sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'amount.numeric' => '账单金额必须是数字',
            'amount.min' => '账单金额最小为0.01',
            'due_date.date' => '到期日期格式不正确',
            'items.array' => '明细项目格式不正确',
            'items.min' => '明细项目至少包含一项',
            'items.*.id.exists' => '明细项目ID不存在',
            'items.*.line_uid.distinct' => '明细项目标识不能重复',
            'items.*.item_name.max' => '项目名称最大255字符',
            'items.*.quantity.required_with' => '项目数量不能为空',
            'items.*.quantity.min' => '项目数量最小为0.001',
            'items.*.unit_price.required_with' => '项目单价不能为空',
            'items.*.unit_price.min' => '项目单价最小为0.01',
        ];
    }

    /**
     * 获取当前请求的 Invoice 模型
     */
    public function getInvoice(): ?Invoice
    {
        $invoiceId = $this->route('invoice') ?? $this->route('id');

        if (is_numeric($invoiceId)) {
            return Invoice::find($invoiceId);
        }

        if ($invoiceId instanceof Invoice) {
            return $invoiceId;
        }

        return null;
    }

    /**
     * 配置验证器实例
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $customerId = $this->input('customer_id');
            if ($customerId) {
                $invoice = $this->getInvoice();
                $customer = \App\Models\Customer::find($customerId);
                if ($customer && $invoice && $customer->store_id != $invoice->store_id) {
                    $validator->errors()->add('customer_id', '该客户不属于此账单的门店');
                }
            }
        });
    }

    /**
     * 处理授权失败的响应
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('需要系统管理员权限或店长权限');
    }
}
