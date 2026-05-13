<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use App\Rules\ValidDiscountData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 应用优惠减免请求验证
 * 
 * 将 PaymentController::applyDiscount() 中的验证逻辑抽离到此处
 */
class ApplyDiscountRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     */
    public function authorize(): bool
    {
        $payment = $this->getPayment();

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
        $payment = $this->getPayment();

        return [
            'discount_data' => [
                'required',
                'array',
                'min:1',
                $payment ? new ValidDiscountData($payment) : 'required',
            ],
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'discount_data.required' => '优惠减免数据不能为空',
            'discount_data.array' => '优惠减免数据格式不正确',
            'discount_data.min' => '优惠减免数据至少包含一项',
        ];
    }

    /**
     * 获取当前请求的 Payment 模型
     */
    public function getPayment(): ?Payment
    {
        $paymentId = $this->route('payment') ?? $this->route('id');

        if (is_numeric($paymentId)) {
            return Payment::find($paymentId);
        }

        if ($paymentId instanceof Payment) {
            return $paymentId;
        }

        return null;
    }

    /**
     * 处理授权失败的响应
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('权限不足');
    }
}
