<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentDiscount;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class InvoiceBusinessAggregationApiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private User $user;

    private Store $store;

    private Store $otherStore;

    private Customer $customer;

    private Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-22 10:30:00'));

        $this->ensureRolesExist();

        $this->store = Store::factory()->create();
        $this->otherStore = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $this->otherCustomer = Customer::factory()->create(['store_id' => $this->store->id]);
        $this->user = $this->createStoreOwner([], $this->store);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_allocatable_returns_visible_customer_outstanding_invoices_with_summary(): void
    {
        $oldest = $this->createInvoice([
            'amount' => 100.00,
            'paid_amount' => 20.00,
            'status' => 'partially_paid',
            'created_at' => '2026-05-20 09:00:00',
        ], 10.00);
        $middle = $this->createInvoice([
            'amount' => 300.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => '2026-05-21 09:00:00',
        ]);
        $newest = $this->createInvoice([
            'amount' => 80.00,
            'paid_amount' => 30.00,
            'status' => 'overdue',
            'created_at' => '2026-05-22 09:00:00',
        ]);

        $this->createInvoice(['amount' => 90.00, 'paid_amount' => 90.00, 'status' => 'paid']);
        $this->createInvoice(['amount' => 70.00, 'paid_amount' => 0.00, 'status' => 'unpaid'], 70.00);
        $this->createInvoice([
            'customer_id' => $this->otherCustomer->id,
            'amount' => 200.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
        ]);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->otherStore->id])->id,
            'amount' => 400.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/invoices/allocatable?store_id={$this->store->id}&customer_id={$this->customer->id}&per_page=2");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.outstanding_count', 3)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.data.0.id', $oldest->id)
            ->assertJsonPath('data.data.1.id', $middle->id);

        $this->assertEquals(420.00, $response->json('data.summary.actual_remaining_total'));
        $this->assertEquals(70.00, $response->json('data.data.0.actual_remaining_amount'));
        $this->assertNotContains($newest->id, collect($response->json('data.data'))->pluck('id'));
    }

    public function test_allocatable_returns_403_for_store_outside_user_scope(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/invoices/allocatable?store_id={$this->otherStore->id}&customer_id={$this->customer->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_today_unpaid_print_tasks_returns_visible_today_outstanding_invoices_with_summary(): void
    {
        $first = $this->createInvoice([
            'amount' => 120.00,
            'paid_amount' => 20.00,
            'status' => 'partially_paid',
            'created_at' => '2026-05-22 08:00:00',
        ]);
        $second = $this->createInvoice([
            'amount' => 210.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => '2026-05-22 09:00:00',
        ], 60.00);
        $third = $this->createInvoice([
            'amount' => 50.00,
            'paid_amount' => 0.00,
            'status' => 'overdue',
            'created_at' => '2026-05-22 11:00:00',
        ]);

        $this->createInvoice(['amount' => 50.00, 'paid_amount' => 50.00, 'status' => 'paid', 'created_at' => '2026-05-22 12:00:00']);
        $this->createInvoice(['amount' => 60.00, 'paid_amount' => 0.00, 'status' => 'unpaid', 'created_at' => '2026-05-21 12:00:00']);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->otherStore->id])->id,
            'amount' => 80.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => '2026-05-22 13:00:00',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/print-tasks/today-unpaid?store_id={$this->store->id}&date=2026-05-22&per_page=2");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.task_count', 3)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.data.0.id', $first->id)
            ->assertJsonPath('data.data.1.id', $second->id);

        $this->assertEquals(300.00, $response->json('data.summary.actual_remaining_total'));
        $this->assertEquals(100.00, $response->json('data.data.0.actual_remaining_amount'));
        $this->assertNotContains($third->id, collect($response->json('data.data'))->pluck('id'));
    }

    public function test_print_details_returns_invoices_with_items_in_requested_order(): void
    {
        $first = $this->createInvoice(['created_at' => '2026-05-22 09:00:00']);
        $second = $this->createInvoice(['created_at' => '2026-05-22 08:00:00']);

        InvoiceItem::factory()->create([
            'invoice_id' => $first->id,
            'item_name' => 'first item',
            'sort_order' => 1,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $second->id,
            'item_name' => 'second item',
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/invoices/print-details', [
            'invoice_ids' => [$second->id, $first->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.items.0.item_name', 'second item')
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.items.0.item_name', 'first item');
    }

    public function test_print_details_rejects_duplicate_invoice_ids(): void
    {
        $invoice = $this->createInvoice();

        Sanctum::actingAs($this->user);

        $this->postJson('/api/invoices/print-details', [
            'invoice_ids' => [$invoice->id, $invoice->id],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_print_details_rejects_unauthorized_or_missing_invoice_ids(): void
    {
        $otherInvoice = $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->otherStore->id])->id,
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/invoices/print-details', [
            'invoice_ids' => [$otherInvoice->id, 999999],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    private function createInvoice(array $attributes = [], ?float $discountAmount = null): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'amount' => 100.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        if ($discountAmount !== null) {
            $payment = Payment::factory()->create([
                'store_id' => $invoice->store_id,
                'customer_id' => $invoice->customer_id,
                'received_by' => $this->user->id,
                'amount' => $discountAmount,
            ]);

            PaymentDiscount::factory()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'discount_amount' => $discountAmount,
                'discount_type' => 'discount',
                'approved_by' => $this->user->id,
            ]);
        }

        return $invoice;
    }
}
