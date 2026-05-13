<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 创建还款记录请求验证
 * 
 * 将 PaymentController::store() 中的验证逻辑抽离到此处
 */
class StorePaymentRequest extends FormRequest
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
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,bank_transfer,wechat,alipay,other',
            'reference_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
            'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => 'required|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            // 优惠抹零相关参数
            'apply_discount' => 'nullable|boolean',
            'discount_data' => 'nullable|array',
            'discount_data.*.invoice_id' => 'required|exists:invoices,id',
            'discount_data.*.amount' => 'required|numeric|min:0.01',
            'discount_data.*.type' => 'nullable|string|in:write_off,discount,promotion',
            'discount_data.*.reason' => 'nullable|string|max:500',
        ];
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
            'amount.required' => '还款金额不能为空',
            'amount.numeric' => '还款金额必须是数字',
            'amount.min' => '还款金额最小为0.01',
            'payment_date.required' => '还款日期不能为空',
            'payment_date.date' => '还款日期格式不正确',
            'payment_method.required' => '支付方式不能为空',
            'payment_method.in' => '支付方式不正确，可选值：cash, bank_transfer, wechat, alipay, other',
            'reference_number.max' => '参考号最大255字符',
            'allocations.*.invoice_id.required' => '分配账单ID不能为空',
            'allocations.*.invoice_id.exists' => '分配账单不存在',
            'allocations.*.amount.required' => '分配金额不能为空',
            'allocations.*.amount.min' => '分配金额最小为0.01',
        ];
    }

    /**
     * 处理授权失败的响应
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('您没有权限在此门店创建还款记录');
    }
}
