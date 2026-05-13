<?php

namespace Database\Factories;

use App\Models\PaymentDiscount;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentDiscount>
 */
class PaymentDiscountFactory extends Factory
{
    protected $model = PaymentDiscount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'discount_amount' => $this->faker->randomFloat(2, 10, 500),
            'discount_type' => $this->faker->randomElement([
                PaymentDiscount::TYPE_DISCOUNT,
                PaymentDiscount::TYPE_PROMOTION,
                PaymentDiscount::TYPE_WRITE_OFF
            ]),
            'reason' => $this->faker->sentence(),
            'approved_by' => User::factory(),
        ];
    }

    /**
     * 创建折扣类型的优惠减免
     */
    public function discount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => PaymentDiscount::TYPE_DISCOUNT,
            'discount_amount' => $this->faker->randomFloat(2, 5, 100),
            'reason' => '折扣优惠',
        ]);
    }

    /**
     * 创建促销类型的优惠减免
     */
    public function promotion(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => PaymentDiscount::TYPE_PROMOTION,
            'discount_amount' => $this->faker->randomFloat(2, 20, 200),
            'reason' => '促销活动优惠',
        ]);
    }

    /**
     * 创建坏账核销类型的优惠减免
     */
    public function writeOff(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => PaymentDiscount::TYPE_WRITE_OFF,
            'discount_amount' => $this->faker->randomFloat(2, 100, 1000),
            'reason' => '坏账核销',
        ]);
    }

    /**
     * 创建小额优惠减免（适合店员权限）
     */
    public function smallAmount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_amount' => $this->faker->randomFloat(2, 1, 50),
            'discount_type' => PaymentDiscount::TYPE_DISCOUNT,
            'reason' => '小额优惠抹零',
        ]);
    }

    /**
     * 创建大额优惠减免（需要管理员权限）
     */
    public function largeAmount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_amount' => $this->faker->randomFloat(2, 500, 2000),
            'discount_type' => PaymentDiscount::TYPE_WRITE_OFF,
            'reason' => '大额坏账核销',
        ]);
    }
}
