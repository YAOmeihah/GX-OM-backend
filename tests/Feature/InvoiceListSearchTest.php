<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class InvoiceListSearchTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-05 09:30:00'));

        $this->ensureRolesExist();

        $this->store = Store::factory()->create(['name' => '星河门店']);
        $this->otherStore = Store::factory()->create(['name' => '隔离门店']);
    }

    public function test_invoice_list_search_matches_invoice_number_and_customer_name(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '星河科技有限公司',
        ]);
        $otherCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '银河贸易有限公司',
        ]);

        $user = $this->createStoreOwner([], $this->store);

        $numberMatch = $this->createInvoice([
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV202406050001',
            'created_at' => '2026-06-05 09:10:00',
        ]);
        $customerMatch = $this->createInvoice([
            'customer_id' => $customer->id,
            'invoice_number' => 'BILL-OTHER-001',
            'created_at' => '2026-06-05 09:00:00',
        ]);
        $this->createInvoice([
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'BILL-OTHER-002',
            'created_at' => '2026-06-05 08:50:00',
        ]);

        Sanctum::actingAs($user);

        $numberResponse = $this->getJson("/api/invoices?store_id={$this->store->id}&search=202406050001");

        $numberResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $numberMatch->id);

        $customerResponse = $this->getJson("/api/invoices?store_id={$this->store->id}&search=星河");

        $customerResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $customerMatch->id);
    }

    public function test_invoice_list_search_keeps_store_scope(): void
    {
        $visibleCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '星河科技有限公司',
        ]);
        $hiddenCustomer = Customer::factory()->create([
            'store_id' => $this->otherStore->id,
            'name' => '星河科技有限公司',
        ]);

        $user = $this->createStoreOwner([], $this->store);

        $visible = $this->createInvoice([
            'store_id' => $this->store->id,
            'customer_id' => $visibleCustomer->id,
            'invoice_number' => 'VISIBLE-001',
        ]);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => $hiddenCustomer->id,
            'invoice_number' => 'HIDDEN-001',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/invoices?search=星河');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $visible->id);
    }

    private function createInvoice(array $attributes = []): Invoice
    {
        $customerId = $attributes['customer_id'] ?? Customer::factory()->create([
            'store_id' => $attributes['store_id'] ?? $this->store->id,
        ])->id;
        $customer = Customer::findOrFail($customerId);

        return Invoice::factory()->create(array_merge([
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
