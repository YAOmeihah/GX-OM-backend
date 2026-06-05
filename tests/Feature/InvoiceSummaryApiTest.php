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

class InvoiceSummaryApiTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-05 10:30:00'));

        $this->ensureRolesExist();

        $this->store = Store::factory()->create();
        $this->otherStore = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    }

    public function test_summary_returns_store_scoped_counts_and_day_deltas(): void
    {
        $user = $this->createStoreOwner([], $this->store);

        $this->createInvoice([
            'status' => 'unpaid',
            'created_at' => '2026-06-05 08:00:00',
        ]);
        $this->createInvoice([
            'status' => 'paid',
            'paid_amount' => 100.00,
            'created_at' => '2026-06-05 09:00:00',
        ]);
        $this->createInvoice([
            'status' => 'overdue',
            'created_at' => '2026-06-05 10:00:00',
        ]);
        $this->createInvoice([
            'status' => 'unpaid',
            'created_at' => '2026-06-04 13:00:00',
        ]);
        $this->createInvoice([
            'status' => 'partially_paid',
            'paid_amount' => 30.00,
            'created_at' => '2026-06-03 13:00:00',
        ]);
        $this->createInvoice([
            'status' => 'overdue',
            'created_at' => '2026-06-02 13:00:00',
        ]);

        $otherCustomer = Customer::factory()->create(['store_id' => $this->otherStore->id]);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => $otherCustomer->id,
            'status' => 'unpaid',
            'created_at' => '2026-06-05 12:00:00',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/invoices/summary?store_id={$this->store->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total.count', 6)
            ->assertJsonPath('data.today.count', 3)
            ->assertJsonPath('data.today.yesterday_count', 1)
            ->assertJsonPath('data.today.delta', 2)
            ->assertJsonPath('data.unpaid.count', 2)
            ->assertJsonPath('data.unpaid.today_count', 1)
            ->assertJsonPath('data.unpaid.yesterday_count', 1)
            ->assertJsonPath('data.unpaid.delta', 0)
            ->assertJsonPath('data.outstanding.count', 5)
            ->assertJsonPath('data.outstanding.today_count', 2)
            ->assertJsonPath('data.outstanding.yesterday_count', 1)
            ->assertJsonPath('data.outstanding.delta', 1)
            ->assertJsonPath('data.overdue.count', 2)
            ->assertJsonPath('data.overdue.today_count', 1)
            ->assertJsonPath('data.overdue.yesterday_count', 0)
            ->assertJsonPath('data.overdue.delta', 1);
    }

    public function test_summary_rejects_store_outside_user_scope(): void
    {
        $user = $this->createStoreOwner([], $this->store);

        Sanctum::actingAs($user);

        $this->getJson("/api/invoices/summary?store_id={$this->otherStore->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function createInvoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 100.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
