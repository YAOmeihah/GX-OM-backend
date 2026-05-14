<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actions = ['create', 'update', 'delete', 'view', 'allocate', 'discount'];
        $action = fake()->randomElement($actions);

        return [
            'user_id' => User::factory(),
            'user_name' => fake()->name(),
            'action' => $action,
            'action_label' => AuditLog::ACTION_LABELS[$action] ?? $action,
            'auditable_type' => 'App\Models\Invoice',
            'auditable_id' => fake()->numberBetween(1, 1000),
            'auditable_label' => '账单',
            'scope_type' => 'store',
            'business_store_id' => Store::factory(),
            'actor_store_id' => null,
            'old_values' => [],
            'new_values' => [],
            'changed_fields' => [],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'request_url' => '/api/'.fake()->slug(),
            'description' => fake()->sentence(),
            'metadata' => [],
            'is_success' => true,
            'error_message' => null,
        ];
    }

    /**
     * 关键操作 (delete)
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'delete',
            'action_label' => '删除',
        ]);
    }
}
