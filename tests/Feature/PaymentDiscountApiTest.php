<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use App\Models\Store;
use App\Models\User;
use App\Services\AutoAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PaymentDiscountApiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    protected User $admin;

    protected User $storeOwner;

    protected User $storeStaff;

    protected Store $store;

    protected Customer $customer;

    protected Invoice $invoice1;

    protected Invoice $invoice2;

    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        // 确保基础角色存在
        $this->ensureRolesExist();

        // 创建测试门店
        $this->store = Store::factory()->create();

        // 创建测试用户（使用 CreatesTestUsers trait）
        $this->admin = $this->createAdmin();
        $this->storeOwner = $this->createStoreOwner([], $this->store);
        $this->storeStaff = $this->createStoreStaff([], $this->store);

        // 创建测试客户
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

        // 创建测试账单（总计2335元）
        $this->invoice1 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1500.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->invoice2 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 835.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // 创建测试还款（2300元）
        $this->payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id,
        ]);
    }

    /** @test */
    public function it_can_detect_payment_gap_via_api()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/payments/{$this->payment->id}/detect-gap");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'gap_info' => [
                        'has_gap' => true,
                        'gap_amount' => 35.00,
                        'total_debt' => 2335.00,
                        'payment_amount' => 2300.00,
                        'can_apply_discount' => true,
                    ],
                    'can_approve_discount' => true,
                ],
            ]);
    }

    /** @test */
    public function it_can_apply_discount_via_api()
    {
        Sanctum::actingAs($this->storeOwner);

        $discountData = [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => 'API测试优惠抹零',
                ],
            ],
        ];

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", $discountData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => '优惠减免处理成功',
            ]);

        // 验证数据库记录
        $this->assertDatabaseHas('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);
    }

    /** @test */
    public function it_rejects_apply_discount_when_auto_allocation_and_discount_exceed_invoice_remaining()
    {
        $this->payment->update(['amount' => 2300.00]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '自动分配后同账单超额减免',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
        ]);
        $this->assertDatabaseMissing('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 35.00,
        ]);
    }

    /** @test */
    public function it_creates_payment_with_discount_via_api()
    {
        Sanctum::actingAs($this->storeOwner);

        $paymentData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'payment_method' => 'cash',
            'apply_discount' => true,
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '创建还款时优惠抹零',
                ],
            ],
        ];

        $response = $this->postJson('/api/payments', $paymentData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => '还款记录创建成功，已处理优惠抹零',
            ]);

        // 验证创建了还款记录
        $this->assertDatabaseHas('payments', [
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
        ]);

        // 验证创建了优惠减免记录
        $this->assertDatabaseHas('payment_discounts', [
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
        ]);
    }

    /** @test */
    public function it_rejects_creating_payment_when_allocations_plus_discounts_exceed_payment_amount_gap()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson('/api/payments', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'payment_method' => 'cash',
            'apply_discount' => true,
            'allocations' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => 2300.00,
                ],
            ],
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 36.00,
                    'type' => 'discount',
                    'reason' => '超过付款差额',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payment_discounts', [
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 36.00,
        ]);
    }

    /** @test */
    public function it_rejects_creating_payment_when_invoice_allocation_and_discount_exceed_actual_remaining()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson('/api/payments', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1500.00,
            'payment_method' => 'cash',
            'apply_discount' => true,
            'allocations' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => 1500.00,
                ],
            ],
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '同账单分配后超额减免',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payments', [
            'customer_id' => $this->customer->id,
            'amount' => 1500.00,
        ]);
        $this->assertDatabaseMissing('payment_allocations', [
            'invoice_id' => $this->invoice1->id,
            'amount' => 1500.00,
        ]);
        $this->assertDatabaseMissing('payment_discounts', [
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 35.00,
        ]);
    }

    /** @test */
    public function it_rejects_discount_invoice_from_another_store_when_creating_payment()
    {
        Sanctum::actingAs($this->storeOwner);

        $otherStore = Store::factory()->create();
        $otherCustomer = Customer::factory()->create(['store_id' => $otherStore->id]);
        $otherInvoice = Invoice::factory()->create([
            'store_id' => $otherStore->id,
            'customer_id' => $otherCustomer->id,
            'amount' => 35.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $response = $this->postJson('/api/payments', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'payment_method' => 'cash',
            'apply_discount' => true,
            'discount_data' => [
                [
                    'invoice_id' => $otherInvoice->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '跨门店优惠应被拒绝',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '账单与还款的客户或门店不匹配',
            ]);

        $this->assertDatabaseMissing('payment_discounts', [
            'invoice_id' => $otherInvoice->id,
            'discount_amount' => 35.00,
        ]);
    }

    /** @test */
    public function it_prevents_unauthorized_discount_operations()
    {
        // 测试未认证用户
        $response = $this->getJson("/api/payments/{$this->payment->id}/detect-gap");
        $response->assertStatus(401);

        // 测试店员进行大额优惠减免
        Sanctum::actingAs($this->storeStaff);

        $discountData = [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => 1000.00, // 超过店员权限
                    'type' => 'write_off',
                    'reason' => '测试权限控制',
                ],
            ],
        ];

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", $discountData);
        $response->assertStatus(403);
    }

    /** @test */
    public function it_returns_403_when_store_staff_applies_manager_approval_discount_type_without_type_error()
    {
        Sanctum::actingAs($this->storeStaff);

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'write_off',
                    'reason' => '店员无权核销',
                ],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'write_off',
        ]);
    }

    /** @test */
    public function it_rejects_discount_entries_with_reason_shorter_than_audit_minimum()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => str_repeat('短', config('payment.audit.min_reason_length') - 1),
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
        ]);
    }

    /** @test */
    public function it_blocks_new_discounts_when_today_store_approved_discounts_exceed_daily_limit()
    {
        config(['payment.daily_discount_limit' => 50]);

        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 45.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 10.00,
                    'type' => 'discount',
                    'reason' => '超过每日限额',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 10.00,
        ]);
    }

    /** @test */
    public function it_revalidates_daily_discount_limit_inside_process_discount_transaction()
    {
        config(['payment.daily_discount_limit' => 50]);

        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 45.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->payment->update(['amount' => 2280.00]);

        $service = app(\App\Services\PaymentDiscountService::class);

        $this->expectException(\App\Services\DiscountValidationException::class);
        $this->expectExceptionMessage('今日优惠减免总额已超过门店每日限额');

        try {
            $service->processDiscountScenario($this->payment, [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 10.00,
                    'type' => 'discount',
                    'reason' => '事务内重新校验限额',
                ],
            ], $this->storeOwner->id);
        } finally {
            $this->assertDatabaseMissing('payment_discounts', [
                'payment_id' => $this->payment->id,
                'invoice_id' => $this->invoice2->id,
                'discount_amount' => 10.00,
            ]);
        }
    }

    /** @test */
    public function it_validates_discount_data_properly()
    {
        Sanctum::actingAs($this->storeOwner);

        // 测试无效的折扣数据
        $invalidData = [
            'discount_data' => [
                [
                    'invoice_id' => 99999, // 不存在的账单
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '测试验证',
                ],
            ],
        ];

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", $invalidData);
        $response->assertStatus(422);

        // 测试金额为负数
        $negativeAmountData = [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice1->id,
                    'amount' => -10.00,
                    'type' => 'discount',
                    'reason' => '测试负数金额',
                ],
            ],
        ];

        $response = $this->postJson("/api/payments/{$this->payment->id}/apply-discount", $negativeAmountData);
        $response->assertStatus(422);
    }

    /** @test */
    public function it_returns_discount_statistics()
    {
        // 创建一些测试折扣记录
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 50.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 25.00,
            'discount_type' => 'promotion',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson('/api/discount-statistics?store_id='.$this->store->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_count' => 2,
                    'total_amount' => 75.00,
                    'average_amount' => 37.50,
                ],
            ]);
    }

    /** @test */
    public function it_includes_discount_info_in_payment_details()
    {
        // 创建优惠减免记录
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/payments/{$this->payment->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'amount',
                    'discounts' => [
                        '*' => [
                            'id',
                            'discount_amount',
                            'discount_type',
                            'reason',
                            'invoice',
                            'approved_by',
                        ],
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_rejects_manual_allocation_above_actual_remaining_after_discounts()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 100.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 800.00,
            'received_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson("/api/payments/{$payment->id}/allocate", [
            'invoice_id' => $this->invoice2->id,
            'amount' => 736.00,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $this->invoice2->id,
        ]);
    }

    /** @test */
    public function it_suggests_allocation_using_actual_remaining_after_discounts()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 100.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2000.00,
            'received_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/payments/{$payment->id}/allocation-suggestion?include_discount=false");

        $response->assertStatus(200)
            ->assertJsonPath('data.suggestion.allocations.1.invoice_id', $this->invoice2->id);

        $this->assertEquals(735.00, $response->json('data.suggestion.allocations.1.remaining_amount'));
        $this->assertEquals(500.00, $response->json('data.suggestion.allocations.1.suggested_amount'));
    }

    /** @test */
    public function it_detects_excess_payment_using_store_scoped_actual_remaining_after_discounts()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 100.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        $otherStore = Store::factory()->create();
        Invoice::factory()->create([
            'store_id' => $otherStore->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id,
        ]);

        $excessInfo = (new AutoAllocationService)->detectExcessPayment($payment);

        $this->assertTrue($excessInfo['is_excess']);
        $this->assertEquals(65.00, $excessInfo['excess_amount']);
        $this->assertEquals(2235.00, $excessInfo['total_debt']);
    }

    /** @test */
    public function it_prevents_model_allocation_above_actual_remaining_after_discounts()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 100.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 800.00,
            'received_by' => $this->storeOwner->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('分配金额超过了账单剩余未付金额');

        try {
            $payment->allocateToInvoice($this->invoice2, 736.00, $this->storeOwner->id, false);
        } finally {
            $this->assertDatabaseMissing('payment_allocations', [
                'payment_id' => $payment->id,
                'invoice_id' => $this->invoice2->id,
            ]);

            $this->assertEquals(0.00, $payment->fresh()->allocated_amount);
            $this->assertEquals(0.00, $this->invoice2->fresh()->paid_amount);
        }
    }

    /** @test */
    public function it_prevents_model_discount_above_locked_actual_remaining()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 835.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 100.00,
            'received_by' => $this->storeOwner->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('优惠减免金额超过了账单剩余未付金额');

        try {
            $payment->createDiscount($this->invoice2, 1.00, 'discount', '锁内复查超额减免', $this->storeOwner->id);
        } finally {
            $this->assertDatabaseMissing('payment_discounts', [
                'payment_id' => $payment->id,
                'invoice_id' => $this->invoice2->id,
                'discount_amount' => 1.00,
            ]);
        }
    }

    /** @test */
    public function it_includes_discount_info_in_customer_debt()
    {
        // 创建优惠减免记录
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice1->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt?store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'customer',
                    'traditional_debt',
                    'actual_debt',
                    'discount_summary',
                    'store_debt_info',
                    'unpaid_invoices' => [
                        '*' => [
                            'id',
                            'amount',
                            'paid_amount',
                            'discount_amount',
                            'actual_remaining',
                            'has_discounts',
                        ],
                    ],
                ],
            ]);
    }

    /** @test */
    public function invoice_with_discounts_rejects_destructive_updates_and_keeps_existing_data()
    {
        $this->invoice2->items()->create([
            'item_name' => '原始项目',
            'quantity' => 1,
            'unit_price' => 835.00,
            'subtotal' => 835.00,
            'sort_order' => 0,
        ]);
        $originalLineUid = $this->invoice2->items()->first()->line_uid;
        $otherCustomer = Customer::factory()->create(['store_id' => $this->store->id]);

        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->putJson("/api/invoices/{$this->invoice2->id}", [
            'amount' => 900.00,
            'customer_id' => $otherCustomer->id,
            'items' => [
                [
                    'item_name' => '新项目',
                    'quantity' => 2,
                    'unit_price' => 450.00,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice2->id,
            'customer_id' => $this->customer->id,
            'amount' => 835.00,
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $this->invoice2->id,
            'line_uid' => $originalLineUid,
            'item_name' => '原始项目',
        ]);
        $this->assertDatabaseMissing('invoice_items', [
            'invoice_id' => $this->invoice2->id,
            'item_name' => '新项目',
        ]);
    }

    /** @test */
    public function invoice_with_discounts_allows_description_only_updates()
    {
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->putJson("/api/invoices/{$this->invoice2->id}", [
            'description' => '折扣后备注更新',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice2->id,
            'customer_id' => $this->customer->id,
            'amount' => 835.00,
            'description' => '折扣后备注更新',
        ]);
    }

    /** @test */
    public function invoice_destructive_update_rechecks_financial_activity_inside_transaction()
    {
        $this->invoice2->items()->create([
            'item_name' => '原始项目',
            'quantity' => 1,
            'unit_price' => 835.00,
            'subtotal' => 835.00,
            'sort_order' => 0,
        ]);
        $originalLineUid = $this->invoice2->items()->first()->line_uid;
        $otherCustomer = Customer::factory()->create(['store_id' => $this->store->id]);
        $payment = $this->payment;
        $invoice = $this->invoice2;
        $storeOwner = $this->storeOwner;

        $request = new class($payment, $invoice, $storeOwner, $otherCustomer) extends \App\Http\Requests\Invoice\UpdateInvoiceRequest
        {
            public function __construct(
                private Payment $payment,
                private Invoice $invoice,
                private User $storeOwner,
                private Customer $otherCustomer
            ) {
                parent::__construct();
            }

            public function validated($key = null, $default = null)
            {
                PaymentDiscount::factory()->create([
                    'payment_id' => $this->payment->id,
                    'invoice_id' => $this->invoice->id,
                    'discount_amount' => 35.00,
                    'discount_type' => 'discount',
                    'approved_by' => $this->storeOwner->id,
                ]);

                return [
                    'customer_id' => $this->otherCustomer->id,
                    'items' => [
                        [
                            'item_name' => '锁内应拒绝项目',
                            'quantity' => 2,
                            'unit_price' => 450.00,
                        ],
                    ],
                ];
            }
        };
        $request->setUserResolver(fn () => $this->storeOwner);

        $response = app(\App\Http\Controllers\InvoiceController::class)->update($request, $this->invoice2->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice2->id,
            'customer_id' => $this->customer->id,
            'amount' => 835.00,
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $this->invoice2->id,
            'line_uid' => $originalLineUid,
            'item_name' => '原始项目',
        ]);
        $this->assertDatabaseMissing('invoice_items', [
            'invoice_id' => $this->invoice2->id,
            'item_name' => '锁内应拒绝项目',
        ]);
    }

    /** @test */
    public function invoice_with_discounts_cannot_be_deleted()
    {
        $discount = PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id,
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->deleteJson("/api/invoices/{$this->invoice2->id}");

        $response->assertStatus(422);

        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice2->id,
        ]);
        $this->assertDatabaseHas('payment_discounts', [
            'id' => $discount->id,
            'invoice_id' => $this->invoice2->id,
        ]);
    }

    /** @test */
    public function invoice_destroy_rechecks_financial_activity_inside_transaction()
    {
        $invoiceReads = 0;
        $insertedDuringLockedRead = false;

        DB::listen(function ($query) use (&$invoiceReads, &$insertedDuringLockedRead) {
            $sql = strtolower($query->sql);
            $hasInvoiceIdBinding = collect($query->bindings)
                ->contains(fn ($binding) => (string) $binding === (string) $this->invoice2->id);
            $isInvoiceRead = str_starts_with($sql, 'select')
                && (str_contains($sql, 'from "invoices"') || str_contains($sql, 'from `invoices`'))
                && $hasInvoiceIdBinding;

            if (! $isInvoiceRead) {
                return;
            }

            $invoiceReads++;

            if ($invoiceReads !== 2) {
                return;
            }

            PaymentDiscount::factory()->create([
                'payment_id' => $this->payment->id,
                'invoice_id' => $this->invoice2->id,
                'discount_amount' => 35.00,
                'discount_type' => 'discount',
                'approved_by' => $this->storeOwner->id,
            ]);
            $insertedDuringLockedRead = true;
        });

        Sanctum::actingAs($this->storeOwner);

        $response = $this->deleteJson("/api/invoices/{$this->invoice2->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice2->id,
            'customer_id' => $this->customer->id,
            'amount' => 835.00,
        ]);
        $this->assertTrue($insertedDuringLockedRead);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditLog::ACTION_DELETE,
            'auditable_type' => Invoice::class,
            'auditable_id' => $this->invoice2->id,
        ]);
    }
}
