<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            'payment_number' => 'PAY-' . date('Ymd') . '-' . Str::random(5),
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'received_by' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'allocated_amount' => 0,
            'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'wechat', 'alipay', 'other']),
            'remarks' => fake()->optional()->sentence(),
        ];
    }

    /**
     * 设置为现金支付
     */
    public function cash(): static
    {
        return $this->state(fn(array $attributes) => [
            'payment_method' => 'cash',
        ]);
    }

    /**
     * 设置为银行转账
     */
    public function bankTransfer(): static
    {
        return $this->state(fn(array $attributes) => [
            'payment_method' => 'bank_transfer',
        ]);
    }
}
