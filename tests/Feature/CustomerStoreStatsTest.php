<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class CustomerStoreStatsTest extends TestCase
{
    // 使用事务包裹以避免污染真实测试数据库
    use CreatesTestUsers, RefreshDatabase;

    private User $user;

    private Store $store;

    private Customer $customer;

    private CustomerStatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRolesExist();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create();
        $this->user->stores()->attach($this->store->id);

        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $this->statsService = app(CustomerStatsService::class);
    }

    public function test_stats_are_created_and_updated_correctly_on_invoice_creation()
    {
        // 1. 初始化，确保无统计数据
        $this->assertDatabaseMissing('customer_store_stats', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);

        // 2. 模拟新开一张 500 元账单
        $invoice1 = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 500.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $this->user->id,
        ]);

        // 因为我们的钩子是挂在 Controller 里的，这里我们手动调用 Service 模拟 Controller 行为
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        // 断言欠款增加到 500
        $this->assertDatabaseHas('customer_store_stats', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'total_debt' => 500.00,
        ]);

        // 3. 模拟再开一张 300 元账单
        $invoice2 = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 300.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $this->user->id,
        ]);

        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        // 断言欠款累积到 800
        $this->assertDatabaseHas('customer_store_stats', [
            'total_debt' => 800.00,
        ]);

        // 4. 模拟结账抵扣 200 元
        $invoice1->update(['paid_amount' => 200.00, 'status' => 'partially_paid']);
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        // 断言欠款下降到 600
        $this->assertDatabaseHas('customer_store_stats', [
            'total_debt' => 600.00,
        ]);
    }

    public function test_customer_list_index_returns_materialized_sort_data()
    {
        // 准备一个测试账单以生成 stats
        Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 1234.56,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $this->user->id,
        ]);
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        // 模拟请求走重构后的 index API
        $response = $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&sort_by=total_debt&sort_dir=desc");

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.id', $this->customer->id);

        // 验证前端能拿到正确的映射属性
        $responseData = $response->json('data.data.0');
        $this->assertEquals(1234.56, $responseData['total_debt']);
    }

    public function test_invoice_update_syncs_old_and_new_customer_store_stats()
    {
        $storeOwner = $this->createStoreOwner([], $this->store);
        $newCustomer = Customer::factory()->create(['store_id' => $this->store->id]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 500.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $storeOwner->id,
        ]);

        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);
        $this->statsService->syncCustomerStoreStats($newCustomer->id, $this->store->id);

        $this->actingAs($storeOwner)
            ->putJson("/api/invoices/{$invoice->id}", [
                'customer_id' => $newCustomer->id,
                'items' => [
                    [
                        'item_name' => '迁移后的项目',
                        'quantity' => 2,
                        'unit_price' => 300.00,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('customer_store_stats', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'total_debt' => 0.00,
        ]);
        $this->assertDatabaseHas('customer_store_stats', [
            'customer_id' => $newCustomer->id,
            'store_id' => $this->store->id,
            'total_debt' => 600.00,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_id' => $newCustomer->id,
            'amount' => 600.00,
        ]);
    }
}
