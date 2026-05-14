<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use App\Models\Store;
use App\Models\User;
use App\Services\AutoAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
