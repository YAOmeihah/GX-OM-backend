<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 创建客户请求验证
 * 
 * 将 CustomerController::store() 中的验证逻辑抽离到此处
 */
class StoreCustomerRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     * 所有已认证用户都可以创建客户
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
        $storeId = $this->input('store_id');
        return [
            'store_id' => 'required|integer|exists:stores,id',
            'name'     => [
                'required', 'string', 'max:255',
                Rule::unique('customers')->where('store_id', $storeId),
            ],
            'phone'    => 'nullable|string|max:20',
            'email'    => 'nullable|email|max:255',
            'address'  => 'nullable|string|max:255',
            'id_card'  => 'nullable|string|max:18',
            'remarks'  => 'nullable|string',
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'store_id.required' => '门店不能为空',
            'store_id.exists'   => '指定的门店不存在',
            'name.required'     => '客户姓名不能为空',
            'name.max'          => '客户姓名最大255字符',
            'name.unique'       => '该门店下已存在同名客户，请使用其他名称或沿用现有客户',
            'phone.max'         => '手机号最大20字符',
            'email.email'       => '邮箱格式不正确',
            'email.max'         => '邮箱最大255字符',
            'address.max'       => '地址最大255字符',
            'id_card.max'       => '身份证号最大18字符',
        ];
    }
}
