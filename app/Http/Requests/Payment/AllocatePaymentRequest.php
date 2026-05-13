<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 还款分配请求验证
 * 
 * 将 PaymentController::allocate() 中的验证逻辑抽离到此处
 */
class AllocatePaymentRequest extends FormRequest
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
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'invoice_id.required' => '账单ID不能为空',
            'invoice_id.exists' => '账单不存在',
            'amount.required' => '分配金额不能为空',
            'amount.numeric' => '分配金额必须是数字',
            'amount.min' => '分配金额最小为0.01',
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
