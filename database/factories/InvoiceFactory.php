<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Store;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 5000);
        $paidAmount = fake()->optional(0.3)->randomFloat(2, 0, $amount);
        $customer = Customer::factory()->create();

        return [
            'invoice_number' => 'INV-' . date('Ymd') . '-' . Str::random(5),
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'created_by' => User::factory(),
            'amount' => $amount,
            'paid_amount' => $paidAmount ?? 0,
            'status' => $paidAmount === null ? 'unpaid' : ($paidAmount >= $amount ? 'paid' : 'partially_paid'),
            'due_date' => fake()->optional()->dateTimeBetween('now', '+60 days'),
            'description' => fake()->optional()->sentence(),
        ];
    }

    /**
     * 设置为未付清状态
     */
    public function unpaid(): static
    {
        return $this->state(fn(array $attributes) => [
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
    }

    /**
     * 设置为已付清状态
     */
    public function paid(): static
    {
        return $this->state(fn(array $attributes) => [
            'paid_amount' => $attributes['amount'],
            'status' => 'paid',
        ]);
    }
}
