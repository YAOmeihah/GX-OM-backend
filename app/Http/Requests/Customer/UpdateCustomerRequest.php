<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 更新客户请求验证
 *
 * 将 CustomerController::update() 中的验证逻辑抽离到此处
 */
class UpdateCustomerRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     * 所有已认证用户都可以更新客户信息
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 获取应用于请求的验证规则
     */
    public function rules(): array
    {
        $customerId = $this->route('customer');
        $customer = \App\Models\Customer::find($customerId);
        $storeId = $customer?->store_id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('customers')->where('store_id', $storeId)->ignore($customerId),
            ],
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'id_card' => 'nullable|string|max:18',
            'remarks' => 'nullable|string',
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'name.required' => '客户姓名不能为空',
            'name.max' => '客户姓名最大255字符',
            'name.unique' => '该门店下已存在同名客户，请使用其他名称或沿用现有客户',
            'phone.max' => '手机号最大20字符',
            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱最大255字符',
            'address.max' => '地址最大255字符',
            'id_card.max' => '身份证号最大18字符',
        ];
    }
}
