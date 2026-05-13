<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 批量还款分配请求验证
 * 
 * 支持一次性分配到多个账单
 */
class BatchAllocatePaymentRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     */
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        // 如果路由参数是 ID，则查询模型
        if (is_numeric($payment)) {
            $payment = Payment::find($payment);
        }

        if (!$payment) {
            return false;
        }

        $user = $this->user();
        return $user->isAdmin() || $user->belongsToStore($payment->store_id);
    }

    /**
     * 获取应用于请求的验证规则
     */
    public function rules(): array
    {
        return [
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|integer|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'allocations.required' => '分配列表不能为空',
            'allocations.array' => '分配列表格式不正确',
            'allocations.min' => '至少需要一笔分配',
            'allocations.*.invoice_id.required' => '账单ID不能为空',
            'allocations.*.invoice_id.integer' => '账单ID必须是整数',
            'allocations.*.invoice_id.exists' => '账单不存在',
            'allocations.*.amount.required' => '分配金额不能为空',
            'allocations.*.amount.numeric' => '分配金额必须是数字',
            'allocations.*.amount.min' => '分配金额最小为0.01',
        ];
    }

    /**
     * 处理授权失败的响应
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('权限不足');
    }
}
