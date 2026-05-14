<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_factory_creates_customer_with_store(): void
    {
        $customer = Customer::factory()->create();

        $this->assertNotNull($customer->store_id);
        $this->assertDatabaseHas('stores', ['id' => $customer->store_id]);
    }

    public function test_invoice_factory_uses_customer_store_by_default(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame($invoice->customer->store_id, $invoice->store_id);
    }

    public function test_payment_factory_uses_customer_store_and_valid_method_by_default(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame($payment->customer->store_id, $payment->store_id);
        $this->assertContains($payment->payment_method, ['cash', 'bank_transfer', 'wechat', 'alipay', 'other']);
        $this->assertFalse($payment->isDirty());
    }
}
