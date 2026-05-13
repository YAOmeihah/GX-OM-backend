<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Store;
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

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-' . date('Ymd') . '-' . Str::random(5),
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'received_by' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'allocated_amount' => 0,
            'payment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'alipay', 'wechat_pay', 'pos']),
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
