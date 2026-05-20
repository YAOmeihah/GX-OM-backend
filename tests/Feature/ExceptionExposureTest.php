<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExceptionExposureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => '系统管理员']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);
        Sanctum::actingAs($this->admin);

        $this->store = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    }

    protected function tearDown(): void
    {
        Invoice::flushEventListeners();
        PaymentAllocation::flushEventListeners();
        Model::clearBootedModels();

        parent::tearDown();
    }

    public function test_invoice_creation_system_failure_hides_raw_exception_details(): void
    {
        Invoice::creating(function () {
            throw new \RuntimeException('Invoice failed: SECRET_INTERNAL_DETAIL');
        });

        $response = $this->postJson('/api/invoices', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000,
            'description' => 'system failure exposure test',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => '账单创建失败',
            ]);
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    public function test_payment_allocation_system_failure_hides_raw_exception_details(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->admin->id,
            'amount' => 1000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->admin->id,
            'amount' => 500,
            'allocated_amount' => 0,
        ]);

        PaymentAllocation::creating(function () {
            throw new \RuntimeException('Allocation failed: SECRET_INTERNAL_DETAIL');
        });

        $response = $this->postJson("/api/payments/{$payment->id}/allocate", [
            'invoice_id' => $invoice->id,
            'amount' => 100,
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => '还款操作失败',
            ]);
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    public function test_invoice_update_system_failure_hides_raw_exception_details(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->admin->id,
            'amount' => 1000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        Invoice::updating(function () {
            throw new \RuntimeException('Invoice update failed: SECRET_INTERNAL_DETAIL');
        });

        $response = $this->putJson("/api/invoices/{$invoice->id}", [
            'description' => 'system failure exposure test',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => '账单更新失败',
            ]);
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    public function test_payment_batch_allocate_system_failure_hides_raw_exception_details(): void
    {
        [$payment, $invoice] = $this->createPaymentAndInvoiceForAllocation();

        PaymentAllocation::creating(function () {
            throw new \RuntimeException('Batch allocation failed: SECRET_INTERNAL_DETAIL');
        });

        $response = $this->postJson("/api/payments/{$payment->id}/batch-allocate", [
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 100],
            ],
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => '批量分配失败',
            ]);
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    public function test_payment_auto_allocate_system_failure_hides_raw_exception_details(): void
    {
        [$payment] = $this->createPaymentAndInvoiceForAllocation();

        PaymentAllocation::creating(function () {
            throw new \RuntimeException('Auto allocation failed: SECRET_INTERNAL_DETAIL');
        });

        $response = $this->postJson("/api/payments/{$payment->id}/auto-allocate", [
            'strategy' => 'oldest_first',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => '自动分配失败',
            ]);
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $response->getContent());
    }

    private function createPaymentAndInvoiceForAllocation(): array
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->admin->id,
            'amount' => 1000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->admin->id,
            'amount' => 500,
            'allocated_amount' => 0,
        ]);

        return [$payment, $invoice];
    }
}
