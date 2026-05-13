<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use App\Models\User;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentDiscountModelTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    protected User $user;
    protected Store $store;
    protected Customer $customer;
    protected Invoice $invoice;
    protected Payment $payment;
    protected PaymentDiscount $discount;

    protected function setUp(): void
    {
        parent::setUp();

        // 确保基础角色存在
        $this->ensureRolesExist();

        $this->store = Store::factory()->create();
        $this->user = $this->createStoreOwner([], $this->store);
        $this->customer = Customer::factory()->create();

        $this->invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'paid_amount' => 0,
            'status' => 'unpaid'
        ]);

        $this->payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 965.00,
            'received_by' => $this->user->id
        ]);

        $this->discount = PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_amount' => 35.00,
            'discount_type' => PaymentDiscount::TYPE_DISCOUNT,
            'approved_by' => $this->user->id
        ]);
    }

    /** @test */
    public function payment_discount_belongs_to_payment()
    {
        $this->assertInstanceOf(Payment::class, $this->discount->payment);
        $this->assertEquals($this->payment->id, $this->discount->payment->id);
    }

    /** @test */
    public function payment_discount_belongs_to_invoice()
    {
        $this->assertInstanceOf(Invoice::class, $this->discount->invoice);
        $this->assertEquals($this->invoice->id, $this->discount->invoice->id);
    }

    /** @test */
    public function payment_discount_belongs_to_approved_by_user()
    {
        $this->assertInstanceOf(User::class, $this->discount->approvedBy);
        $this->assertEquals($this->user->id, $this->discount->approvedBy->id);
    }

    /** @test */
    public function payment_has_many_discounts()
    {
        $this->assertTrue($this->payment->discounts()->exists());
        $this->assertCount(1, $this->payment->discounts);
        $this->assertEquals(35.00, $this->payment->total_discount_amount);
    }

    /** @test */
    public function invoice_has_many_discounts()
    {
        $this->assertTrue($this->invoice->discounts()->exists());
        $this->assertCount(1, $this->invoice->discounts);
        $this->assertEquals(35.00, $this->invoice->total_discount_amount);
    }

    /** @test */
    public function payment_discount_has_type_checking_methods()
    {
        $this->assertTrue($this->discount->isDiscount());
        $this->assertFalse($this->discount->isWriteOff());
        $this->assertFalse($this->discount->isPromotion());

        // 测试其他类型
        $writeOffDiscount = PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_type' => PaymentDiscount::TYPE_WRITE_OFF,
            'approved_by' => $this->user->id
        ]);

        $this->assertTrue($writeOffDiscount->isWriteOff());
        $this->assertFalse($writeOffDiscount->isDiscount());
    }

    /** @test */
    public function payment_discount_has_formatted_amount_attribute()
    {
        $this->assertEquals('35.00', $this->discount->formatted_discount_amount);
    }

    /** @test */
    public function payment_discount_has_type_name_attribute()
    {
        $this->assertEquals('折扣', $this->discount->discount_type_name);

        $writeOffDiscount = PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_type' => PaymentDiscount::TYPE_WRITE_OFF,
            'approved_by' => $this->user->id
        ]);

        $this->assertEquals('坏账核销', $writeOffDiscount->discount_type_name);
    }

    /** @test */
    public function payment_discount_has_query_scopes()
    {
        // 创建不同类型的折扣记录
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_type' => PaymentDiscount::TYPE_PROMOTION,
            'approved_by' => $this->user->id
        ]);

        // 测试按类型筛选
        $discountTypeRecords = PaymentDiscount::byType(PaymentDiscount::TYPE_DISCOUNT)->get();
        $this->assertCount(1, $discountTypeRecords);

        $promotionTypeRecords = PaymentDiscount::byType(PaymentDiscount::TYPE_PROMOTION)->get();
        $this->assertCount(1, $promotionTypeRecords);

        // 测试按还款筛选
        $paymentDiscounts = PaymentDiscount::byPayment($this->payment->id)->get();
        $this->assertCount(2, $paymentDiscounts);

        // 测试按账单筛选
        $invoiceDiscounts = PaymentDiscount::byInvoice($this->invoice->id)->get();
        $this->assertCount(2, $invoiceDiscounts);

        // 测试按审批人筛选
        $approverDiscounts = PaymentDiscount::byApprover($this->user->id)->get();
        $this->assertCount(2, $approverDiscounts);
    }

    /** @test */
    public function payment_can_create_discount()
    {
        $newInvoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 500.00,
            'paid_amount' => 0,
            'status' => 'unpaid'
        ]);

        $discount = $this->payment->createDiscount(
            $newInvoice,
            25.00,
            PaymentDiscount::TYPE_PROMOTION,
            '测试创建折扣',
            $this->user->id
        );

        $this->assertInstanceOf(PaymentDiscount::class, $discount);
        $this->assertEquals(25.00, $discount->discount_amount);
        $this->assertEquals(PaymentDiscount::TYPE_PROMOTION, $discount->discount_type);
        $this->assertEquals('测试创建折扣', $discount->reason);

        // 验证数据库记录
        $this->assertDatabaseHas('payment_discounts', [
            'payment_id' => $this->payment->id,
            'invoice_id' => $newInvoice->id,
            'discount_amount' => 25.00,
            'discount_type' => PaymentDiscount::TYPE_PROMOTION
        ]);
    }

    /** @test */
    public function payment_has_discount_summary()
    {
        // 创建多个不同类型的折扣
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_amount' => 15.00,
            'discount_type' => PaymentDiscount::TYPE_PROMOTION,
            'approved_by' => $this->user->id
        ]);

        $summary = $this->payment->discount_summary;

        $this->assertEquals(50.00, $summary['total_amount']); // 35 + 15
        $this->assertArrayHasKey('by_type', $summary);
        $this->assertEquals(1, $summary['by_type']['discount']['count']);
        $this->assertEquals(35.00, $summary['by_type']['discount']['amount']);
        $this->assertEquals(1, $summary['by_type']['promotion']['count']);
        $this->assertEquals(15.00, $summary['by_type']['promotion']['amount']);
    }

    /** @test */
    public function invoice_calculates_actual_remaining_amount_correctly()
    {
        // 初始状态：1000元账单，0元已付，35元折扣
        $this->assertEquals(965.00, $this->invoice->actual_remaining_amount);

        // 添加付款
        $this->invoice->update(['paid_amount' => 500.00]);
        $this->assertEquals(465.00, $this->invoice->actual_remaining_amount);

        // 添加更多折扣
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->invoice->id,
            'discount_amount' => 65.00,
            'discount_type' => PaymentDiscount::TYPE_PROMOTION,
            'approved_by' => $this->user->id
        ]);

        $this->invoice->refresh();
        $this->assertEquals(400.00, $this->invoice->actual_remaining_amount); // 1000 - 500 - 35 - 65
    }

    /** @test */
    public function invoice_updates_status_with_discounts()
    {
        // 初始状态应该是unpaid
        $this->assertEquals('unpaid', $this->invoice->status);

        // 添加部分付款
        $this->invoice->update(['paid_amount' => 500.00]);
        $this->invoice->updateStatusWithDiscounts();
        $this->assertEquals('partially_paid', $this->invoice->status);

        // 添加足够的付款和折扣使其完全结清
        $this->invoice->update(['paid_amount' => 965.00]);
        $this->invoice->updateStatusWithDiscounts();
        $this->assertEquals('paid', $this->invoice->status);
    }

    /** @test */
    public function invoice_checks_if_fully_settled()
    {
        $this->assertFalse($this->invoice->isFullySettled());

        // 添加付款使其完全结清（考虑折扣）
        $this->invoice->update(['paid_amount' => 965.00]);
        $this->assertTrue($this->invoice->isFullySettled());
    }

    /** @test */
    public function customer_calculates_actual_total_debt()
    {
        // 创建另一个账单
        $invoice2 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 800.00,
            'paid_amount' => 200.00,
            'status' => 'partially_paid'
        ]);

        // 为第二个账单创建折扣
        PaymentDiscount::factory()->create([
            'payment_id' => $this->payment->id,
            'invoice_id' => $invoice2->id,
            'discount_amount' => 50.00,
            'discount_type' => PaymentDiscount::TYPE_DISCOUNT,
            'approved_by' => $this->user->id
        ]);

        // 传统欠款：(1000 - 0) + (800 - 200) = 1600
        $this->assertEquals(1600.00, $this->customer->total_debt);

        // 实际欠款：(1000 - 0 - 35) + (800 - 200 - 50) = 1515
        $this->assertEquals(1515.00, $this->customer->actual_total_debt);
    }
}
