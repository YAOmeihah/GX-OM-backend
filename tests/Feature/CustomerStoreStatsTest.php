<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerStatsService;
use App\Services\CustomerWorkbenchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_customer_list_filters_workbench_segments_and_returns_row_flags()
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $todayDebtCustomer = $this->customer;
        $todayDebtCustomer->update(['name' => '今日欠款客户']);
        Invoice::factory()->create([
            'customer_id' => $todayDebtCustomer->id,
            'store_id' => $this->store->id,
            'amount' => 200.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-02 09:00:00',
        ]);
        $this->statsService->syncCustomerStoreStats($todayDebtCustomer->id, $this->store->id);

        $overdueCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '逾期欠款客户',
        ]);
        $overdueInvoice = Invoice::factory()->create([
            'customer_id' => $overdueCustomer->id,
            'store_id' => $this->store->id,
            'amount' => 500.00,
            'paid_amount' => 0.00,
            'status' => 'overdue',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-01 09:00:00',
            'due_date' => '2026-06-01 00:00:00',
        ]);
        $yesterdayPayment = Payment::factory()->create([
            'customer_id' => $overdueCustomer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 100.00,
            'allocated_amount' => 100.00,
            'created_at' => '2026-06-01 10:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $yesterdayPayment->id,
            'invoice_id' => $overdueInvoice->id,
            'amount' => 100.00,
            'allocated_by' => $this->user->id,
            'created_at' => '2026-06-01 10:05:00',
            'updated_at' => '2026-06-01 10:05:00',
        ]);
        $this->statsService->syncCustomerStoreStats($overdueCustomer->id, $this->store->id);

        $settledCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '无欠款客户',
        ]);
        Invoice::factory()->create([
            'customer_id' => $settledCustomer->id,
            'store_id' => $this->store->id,
            'amount' => 300.00,
            'paid_amount' => 300.00,
            'status' => 'paid',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-02 10:00:00',
        ]);
        $this->statsService->syncCustomerStoreStats($settledCustomer->id, $this->store->id);

        $debtResponse = $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&has_debt=true&sort_by=total_debt&sort_dir=desc");

        $debtResponse->assertStatus(200)
            ->assertJsonCount(2, 'data.data');
        $this->assertSame(
            [$overdueCustomer->id, $todayDebtCustomer->id],
            array_column($debtResponse->json('data.data'), 'id')
        );
        $debtResponse->assertJsonPath('data.data.0.is_debt_customer', true)
            ->assertJsonPath('data.data.0.is_overdue', true)
            ->assertJsonPath('data.data.1.has_today_transaction', true);

        $todayResponse = $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&transaction_date=2026-06-02&sort_by=last_transaction_at&sort_dir=desc");

        $todayResponse->assertStatus(200);
        $this->assertSame(
            [$settledCustomer->id, $todayDebtCustomer->id],
            array_column($todayResponse->json('data.data'), 'id')
        );

        $overdueResponse = $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&overdue=true");

        $overdueResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $overdueCustomer->id)
            ->assertJsonPath('data.data.0.is_overdue', true);
    }

    public function test_customer_workbench_summary_returns_cards_tabs_and_debt_trend()
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $this->customer->update(['name' => '今日欠款客户']);
        Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 200.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-02 09:00:00',
        ]);
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        $overdueCustomer = Customer::factory()->create(['store_id' => $this->store->id]);
        $overdueInvoice = Invoice::factory()->create([
            'customer_id' => $overdueCustomer->id,
            'store_id' => $this->store->id,
            'amount' => 500.00,
            'paid_amount' => 100.00,
            'status' => 'overdue',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-01 09:00:00',
            'due_date' => '2026-06-01 00:00:00',
        ]);
        $overduePayment = Payment::factory()->create([
            'customer_id' => $overdueCustomer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 100.00,
            'allocated_amount' => 100.00,
            'created_at' => '2026-06-01 10:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $overduePayment->id,
            'invoice_id' => $overdueInvoice->id,
            'amount' => 100.00,
            'allocated_by' => $this->user->id,
            'created_at' => '2026-06-01 10:05:00',
            'updated_at' => '2026-06-01 10:05:00',
        ]);
        $this->statsService->syncCustomerStoreStats($overdueCustomer->id, $this->store->id);

        Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 20.00,
            'created_at' => '2026-06-02 11:00:00',
        ]);
        Payment::factory()->create([
            'customer_id' => $overdueCustomer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 15.00,
            'created_at' => '2026-06-01 11:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/customers/workbench-summary?store_id={$this->store->id}&date=2026-06-02&trend_days=3");

        $response->assertStatus(200)
            ->assertJsonPath('data.debt.total_amount', '600.00')
            ->assertJsonPath('data.debt.yesterday_total_amount', '400.00')
            ->assertJsonPath('data.debt.delta_amount', '200.00')
            ->assertJsonPath('data.debt_customers.count', 2)
            ->assertJsonPath('data.debt_customers.yesterday_count', 1)
            ->assertJsonPath('data.debt_customers.delta_count', 1)
            ->assertJsonPath('data.today_payments.amount', '20.00')
            ->assertJsonPath('data.today_payments.yesterday_amount', '115.00')
            ->assertJsonPath('data.today_payments.delta_amount', '-95.00')
            ->assertJsonPath('data.today_payments.customer_count', 1)
            ->assertJsonPath('data.tabs.all', 2)
            ->assertJsonPath('data.tabs.debt', 2)
            ->assertJsonPath('data.tabs.today_transaction', 1)
            ->assertJsonPath('data.tabs.overdue', 1)
            ->assertJsonPath('data.tabs.abnormal', 0);

        $this->assertSame(
            ['2026-05-31', '2026-06-01', '2026-06-02'],
            array_column($response->json('data.debt.trend'), 'date')
        );
        $this->assertSame(
            ['0.00', '400.00', '600.00'],
            array_column($response->json('data.debt.trend'), 'amount')
        );
    }

    public function test_customer_workbench_summary_uses_historical_allocation_dates_for_yesterday_debt()
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 200.00,
            'paid_amount' => 50.00,
            'status' => 'partially_paid',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-01 09:00:00',
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 50.00,
            'allocated_amount' => 50.00,
            'created_at' => '2026-06-02 10:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 50.00,
            'allocated_by' => $this->user->id,
            'created_at' => '2026-06-02 10:05:00',
            'updated_at' => '2026-06-02 10:05:00',
        ]);
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        $response = $this->actingAs($this->user)
            ->getJson("/api/customers/workbench-summary?store_id={$this->store->id}&date=2026-06-02&trend_days=2");

        $response->assertStatus(200)
            ->assertJsonPath('data.debt.total_amount', '150.00')
            ->assertJsonPath('data.debt.yesterday_total_amount', '200.00')
            ->assertJsonPath('data.debt.delta_amount', '-50.00');

        $this->assertSame(
            [
                ['date' => '2026-06-01', 'amount' => '200.00'],
                ['date' => '2026-06-02', 'amount' => '150.00'],
            ],
            $response->json('data.debt.trend')
        );
    }

    public function test_customer_workbench_debt_trend_does_not_query_snapshot_once_per_day()
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'amount' => 200.00,
            'paid_amount' => 50.00,
            'status' => 'partially_paid',
            'created_by' => $this->user->id,
            'created_at' => '2026-05-28 09:00:00',
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->user->id,
            'amount' => 50.00,
            'allocated_amount' => 50.00,
            'created_at' => '2026-06-01 10:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 50.00,
            'allocated_by' => $this->user->id,
            'created_at' => '2026-06-01 10:05:00',
            'updated_at' => '2026-06-01 10:05:00',
        ]);
        $this->statsService->syncCustomerStoreStats($this->customer->id, $this->store->id);

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)
            ->getJson("/api/customers/workbench-summary?store_id={$this->store->id}&date=2026-06-02&trend_days=7");

        $response->assertStatus(200);
        $this->assertSame([
            ['date' => '2026-05-27', 'amount' => '0.00'],
            ['date' => '2026-05-28', 'amount' => '200.00'],
            ['date' => '2026-05-29', 'amount' => '200.00'],
            ['date' => '2026-05-30', 'amount' => '200.00'],
            ['date' => '2026-05-31', 'amount' => '200.00'],
            ['date' => '2026-06-01', 'amount' => '150.00'],
            ['date' => '2026-06-02', 'amount' => '150.00'],
        ], $response->json('data.debt.trend'));

        $allocationQueries = collect(DB::getQueryLog())->filter(function (array $query) {
            return str_contains($query['query'], 'payment_allocations');
        });

        $this->assertLessThanOrEqual(3, $allocationQueries->count());
    }

    public function test_customer_workbench_business_logic_lives_outside_controller()
    {
        $this->assertTrue(class_exists(CustomerWorkbenchService::class));
        $this->assertFalse(method_exists(CustomerController::class, 'debtSummaryAsOf'));
        $this->assertFalse(method_exists(CustomerController::class, 'debtTrend'));
        $this->assertFalse(method_exists(CustomerController::class, 'debtSnapshotQuery'));
    }

    public function test_customer_list_row_flags_do_not_query_overdue_status_per_customer()
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        for ($i = 0; $i < 5; $i++) {
            $customer = Customer::factory()->create(['store_id' => $this->store->id]);
            Invoice::factory()->create([
                'customer_id' => $customer->id,
                'store_id' => $this->store->id,
                'amount' => 100.00,
                'paid_amount' => 0.00,
                'status' => $i === 0 ? 'overdue' : 'unpaid',
                'created_by' => $this->user->id,
                'created_at' => '2026-06-02 09:00:00',
            ]);
            $this->statsService->syncCustomerStoreStats($customer->id, $this->store->id);
        }

        DB::enableQueryLog();
        $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&per_page=10")
            ->assertStatus(200);

        $perCustomerOverdueQueries = collect(DB::getQueryLog())->filter(function (array $query) {
            return str_contains($query['query'], 'from "invoices"')
                && str_contains($query['query'], '"customer_id" = ?')
                && str_contains($query['query'], '"status" = ?');
        });

        $this->assertCount(0, $perCustomerOverdueQueries);
    }

    public function test_customer_workbench_rejects_invalid_dates()
    {
        $this->actingAs($this->user)
            ->getJson("/api/customers/workbench-summary?store_id={$this->store->id}&date=not-a-date")
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->getJson("/api/customers?store_id={$this->store->id}&transaction_date=not-a-date")
            ->assertStatus(422);
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
