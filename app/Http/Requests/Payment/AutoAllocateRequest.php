<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 自动分配请求验证
 *
 * 将 PaymentController::autoAllocate() 中的验证逻辑抽离到此处
 */
class AutoAllocateRequest extends FormRequest
{
    /**
     * 判断用户是否有权限执行此请求
     */
    public function authorize(): bool
    {
        $payment = $this->getPayment();

        if (! $payment) {
            return false;
        }

        $user = $this->user();

        return $user->isAdmin() || $user->isManagerOfStore($payment->store_id);
    }

    /**
     * 获取应用于请求的验证规则
     */
    public function rules(): array
    {
        return [
            'strategy' => [
                'nullable',
                'string',
                Rule::in(['oldest_first', 'due_date_first', 'smallest_first', 'largest_first', 'overdue_first']),
            ],
            'confirm_excess' => 'nullable|boolean',
            'include_discount' => [
                'nullable',
                Rule::notIn([true, 1, '1', 'true', 'on', 'yes']),
            ],
        ];
    }

    /**
     * 获取验证错误的自定义消息
     */
    public function messages(): array
    {
        return [
            'strategy.in' => '分配策略不正确，可选值：oldest_first, due_date_first, smallest_first, largest_first, overdue_first',
            'include_discount.not_in' => '自动分配不支持自动减免，请使用独立减免或一键清账流程',
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
        throw new \Illuminate\Auth\Access\AuthorizationException('需要系统管理员权限或店长权限');
    }
}
