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

class DashboardInvoiceSummaryTest extends TestCase
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

    public function test_overview_includes_store_scoped_invoice_summary(): void
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

        $otherCustomer = Customer::factory()->create(['store_id' => $this->otherStore->id]);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => $otherCustomer->id,
            'status' => 'unpaid',
            'created_at' => '2026-06-05 12:00:00',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/dashboard/overview?store_id={$this->store->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_summary.total.count', 5)
            ->assertJsonPath('data.invoice_summary.today.count', 3)
            ->assertJsonPath('data.invoice_summary.today.yesterday_count', 1)
            ->assertJsonPath('data.invoice_summary.today.delta', 2)
            ->assertJsonPath('data.invoice_summary.unpaid.count', 2)
            ->assertJsonPath('data.invoice_summary.unpaid.today_count', 1)
            ->assertJsonPath('data.invoice_summary.unpaid.yesterday_count', 1)
            ->assertJsonPath('data.invoice_summary.unpaid.delta', 0)
            ->assertJsonPath('data.invoice_summary.outstanding.count', 4)
            ->assertJsonPath('data.invoice_summary.outstanding.today_count', 2)
            ->assertJsonPath('data.invoice_summary.outstanding.yesterday_count', 1)
            ->assertJsonPath('data.invoice_summary.outstanding.delta', 1)
            ->assertJsonPath('data.invoice_summary.overdue.count', 1)
            ->assertJsonPath('data.invoice_summary.overdue.today_count', 1)
            ->assertJsonPath('data.invoice_summary.overdue.yesterday_count', 0)
            ->assertJsonPath('data.invoice_summary.overdue.delta', 1);
    }

    public function test_overview_invoice_summary_aggregates_all_visible_stores(): void
    {
        $user = $this->createStoreOwner([], $this->store);
        $user->stores()->attach($this->otherStore->id);

        $this->createInvoice([
            'status' => 'unpaid',
            'created_at' => '2026-06-05 08:00:00',
        ]);

        $otherCustomer = Customer::factory()->create(['store_id' => $this->otherStore->id]);
        $this->createInvoice([
            'store_id' => $this->otherStore->id,
            'customer_id' => $otherCustomer->id,
            'status' => 'overdue',
            'created_at' => '2026-06-04 12:00:00',
        ]);

        $hiddenStore = Store::factory()->create();
        $hiddenCustomer = Customer::factory()->create(['store_id' => $hiddenStore->id]);
        $this->createInvoice([
            'store_id' => $hiddenStore->id,
            'customer_id' => $hiddenCustomer->id,
            'status' => 'paid',
            'paid_amount' => 100.00,
            'created_at' => '2026-06-05 09:00:00',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_summary.total.count', 2)
            ->assertJsonPath('data.invoice_summary.today.count', 1)
            ->assertJsonPath('data.invoice_summary.today.yesterday_count', 1)
            ->assertJsonPath('data.invoice_summary.today.delta', 0)
            ->assertJsonPath('data.invoice_summary.outstanding.count', 2)
            ->assertJsonPath('data.invoice_summary.overdue.count', 1);
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
