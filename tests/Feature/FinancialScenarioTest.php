<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use App\Models\User;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use App\Services\PaymentDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * 测试用户描述的具体财务场景：
 * - 客户在某个门店下有多个未付账单，总金额2335元
 * - 店员上门收款2300元
 * - 差额35元需要优惠抹零处理
 */
class FinancialScenarioTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    protected User $storeOwner;
    protected User $storeStaff;
    protected Store $store;
    protected Customer $customer;
    protected Invoice $invoice1;
    protected Invoice $invoice2;

    protected function setUp(): void
    {
        parent::setUp();

        // 确保基础角色存在
        $this->ensureRolesExist();

        // 创建门店
        $this->store = Store::factory()->create(['name' => '测试门店']);

        // 创建店长和店员（使用 CreatesTestUsers trait）
        $this->storeOwner = $this->createStoreOwner([], $this->store);
        $this->storeStaff = $this->createStoreStaff([], $this->store);

        // 创建客户
        $this->customer = Customer::factory()->create(['name' => '张三']);

        // 创建两个未付账单，总计2335元
        $this->invoice1 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-001',
            'amount' => 1500.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'due_date' => now()->addDays(20)
        ]);

        $this->invoice2 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-002',
            'amount' => 835.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'due_date' => now()->addDays(25)
        ]);
    }

    /** @test */
    public function it_handles_the_complete_financial_scenario()
    {
        Sanctum::actingAs($this->storeOwner);

        // 第一步：验证初始状态
        $this->assertEquals(2335.00, $this->customer->total_debt);
        $this->assertEquals(2335.00, $this->customer->actual_total_debt);

        // 第二步：店员上门收款2300元，同时处理35元优惠抹零
        $paymentData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'payment_method' => 'cash',
            'remarks' => '上门收款',
            'apply_discount' => true,
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '优惠抹零'
                ]
            ]
        ];

        $response = $this->postJson('/api/payments', $paymentData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => '还款记录创建成功，已处理优惠抹零'
            ]);

        // 第三步：验证还款记录创建成功
        $payment = Payment::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(2300.00, $payment->amount);
        $this->assertEquals($this->storeOwner->id, $payment->received_by);

        // 第四步：验证优惠减免记录创建成功
        $discount = PaymentDiscount::where('payment_id', $payment->id)->first();
        $this->assertNotNull($discount);
        $this->assertEquals(35.00, $discount->discount_amount);
        $this->assertEquals('discount', $discount->discount_type);
        $this->assertEquals($this->invoice2->id, $discount->invoice_id);
        $this->assertEquals('优惠抹零', $discount->reason);

        // 第五步：验证账单状态更新
        $this->invoice1->refresh();
        $this->invoice2->refresh();

        // invoice1应该是已付清（1500元全部分配）
        $this->assertEquals('paid', $this->invoice1->status);
        $this->assertEquals(1500.00, $this->invoice1->paid_amount);

        // invoice2应该是已付清（800元分配 + 35元优惠减免）
        $this->assertEquals('paid', $this->invoice2->status);
        $this->assertEquals(800.00, $this->invoice2->paid_amount);
        $this->assertEquals(35.00, $this->invoice2->total_discount_amount);
        $this->assertEquals(0.00, $this->invoice2->actual_remaining_amount);

        // 第六步：验证客户欠款状态
        $this->customer->refresh();
        $this->assertEquals(0.00, $this->customer->total_debt);
        $this->assertEquals(0.00, $this->customer->actual_total_debt);
    }

    /** @test */
    public function it_detects_gap_correctly_in_the_scenario()
    {
        // 创建还款记录但不处理优惠减免
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/payments/{$payment->id}/detect-gap");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'gap_info' => [
                        'has_gap' => true,
                        'gap_amount' => 35.00,
                        'total_debt' => 2335.00,
                        'payment_amount' => 2300.00,
                        'can_apply_discount' => true
                    ],
                    'can_approve_discount' => true
                ]
            ]);

        // 验证返回的未付账单信息
        $gapInfo = $response->json('data.gap_info');
        $this->assertCount(2, $gapInfo['unpaid_invoices']);
        // 建议的折扣类型可能是 'discount' 或 'promotion'，取决于系统配置
        $this->assertContains($gapInfo['suggested_discount_type'], ['discount', 'promotion']);
    }

    /** @test */
    public function it_handles_step_by_step_discount_application()
    {
        // 第一步：创建还款记录
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id
        ]);

        Sanctum::actingAs($this->storeOwner);

        // 第二步：检测差额
        $gapResponse = $this->getJson("/api/payments/{$payment->id}/detect-gap");
        $gapResponse->assertStatus(200);

        // 第三步：应用优惠减免
        $discountData = [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '客户优惠抹零'
                ]
            ]
        ];

        $discountResponse = $this->postJson("/api/payments/{$payment->id}/apply-discount", $discountData);

        $discountResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => '优惠减免处理成功'
            ]);

        // 第四步：验证最终状态
        $this->invoice1->refresh();
        $this->invoice2->refresh();

        $this->assertEquals('paid', $this->invoice1->status);
        $this->assertEquals('paid', $this->invoice2->status);
        $this->assertEquals(0.00, $this->customer->fresh()->actual_total_debt);
    }

    /** @test */
    public function it_provides_comprehensive_debt_information()
    {
        // 创建还款和优惠减免
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id
        ]);

        PaymentDiscount::factory()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt?store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'customer',
                    'traditional_debt',
                    'actual_debt',
                    'discount_summary' => [
                        'total_count',
                        'total_amount',
                        'by_type'
                    ],
                    'store_debt_info' => [
                        'total_invoices',
                        'unpaid_invoices',
                        'total_amount',
                        'paid_amount',
                        'discount_amount',
                        'traditional_debt',
                        'actual_debt',
                        'discount_rate'
                    ],
                    'unpaid_invoices'
                ]
            ]);

        $data = $response->json('data');

        // 验证门店欠款信息
        $this->assertEquals(2, $data['store_debt_info']['total_invoices']);
        $this->assertEquals(2335.00, $data['store_debt_info']['total_amount']);
        $this->assertEquals(35.00, $data['store_debt_info']['discount_amount']);
        $this->assertEquals(2300.00, $data['store_debt_info']['actual_debt']);

        // 验证优惠减免统计
        $this->assertEquals(1, $data['discount_summary']['total_count']);
        $this->assertEquals(35.00, $data['discount_summary']['total_amount']);
    }

    /** @test */
    public function it_validates_permissions_in_the_scenario()
    {
        $payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeStaff->id
        ]);

        // 测试店员权限（应该可以进行小额优惠减免）
        Sanctum::actingAs($this->storeStaff);

        $discountData = [
            'discount_data' => [
                [
                    'invoice_id' => $this->invoice2->id,
                    'amount' => 35.00,
                    'type' => 'discount',
                    'reason' => '店员优惠抹零'
                ]
            ]
        ];

        $response = $this->postJson("/api/payments/{$payment->id}/apply-discount", $discountData);

        // 根据配置，店员应该可以进行小额折扣
        if (in_array('store_staff', config('payment.discount_types.discount.approval_roles', []))) {
            $response->assertStatus(200);
        } else {
            $response->assertStatus(403);
        }
    }

    /** @test */
    public function it_generates_discount_statistics_for_the_scenario()
    {
        // 创建多个优惠减免记录模拟实际使用
        $payment1 = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 2300.00,
            'received_by' => $this->storeOwner->id
        ]);

        PaymentDiscount::factory()->create([
            'payment_id' => $payment1->id,
            'invoice_id' => $this->invoice2->id,
            'discount_amount' => 35.00,
            'discount_type' => 'discount',
            'approved_by' => $this->storeOwner->id
        ]);

        // 创建另一个客户和优惠减免
        $customer2 = Customer::factory()->create();
        $invoice3 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer2->id,
            'amount' => 1200.00
        ]);

        $payment2 = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer2->id,
            'amount' => 1180.00,
            'received_by' => $this->storeOwner->id
        ]);

        PaymentDiscount::factory()->create([
            'payment_id' => $payment2->id,
            'invoice_id' => $invoice3->id,
            'discount_amount' => 20.00,
            'discount_type' => 'promotion',
            'approved_by' => $this->storeOwner->id
        ]);

        Sanctum::actingAs($this->storeOwner);

        $response = $this->getJson("/api/discount-statistics?store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_count' => 2,
                    'total_amount' => 55.00,
                    'average_amount' => 27.50
                ]
            ]);

        $data = $response->json('data');
        $this->assertArrayHasKey('discount', $data['by_type']);
        $this->assertArrayHasKey('promotion', $data['by_type']);
        $this->assertEquals(35.00, $data['by_type']['discount']['amount']);
        $this->assertEquals(20.00, $data['by_type']['promotion']['amount']);
    }
}
