<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PaymentListSearchFilterTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-07 10:30:00'));
        $this->ensureRolesExist();

        $this->store = Store::factory()->create(['name' => '星河门店']);
        $this->otherStore = Store::factory()->create(['name' => '隔离门店']);
    }

    public function test_payment_list_search_matches_payment_number_reference_remarks_customer_name_and_phone(): void
    {
        $owner = $this->createStoreOwner([], $this->store);

        $numberMatch = $this->createPayment(['payment_number' => 'PAY-SEARCH-001']);
        $referenceMatch = $this->createPayment(['reference_number' => 'BANK-FLOW-888']);
        $remarksMatch = $this->createPayment(['remarks' => '客户备注含星河']);
        $nameCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '星河科技有限公司',
            'phone' => '13900000001',
        ]);
        $nameMatch = $this->createPayment(['customer_id' => $nameCustomer->id]);
        $phoneCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '普通客户',
            'phone' => '18812345678',
        ]);
        $phoneMatch = $this->createPayment(['customer_id' => $phoneCustomer->id]);
        $this->createPayment(['payment_number' => 'PAY-NO-MATCH']);

        Sanctum::actingAs($owner);

        $this->assertPaymentSearchReturns('SEARCH-001', [$numberMatch->id]);
        $this->assertPaymentSearchReturns('FLOW-888', [$referenceMatch->id]);
        $this->assertPaymentSearchReturns('星河', [$remarksMatch->id, $nameMatch->id]);
        $this->assertPaymentSearchReturns('18812345678', [$phoneMatch->id]);
    }

    public function test_payment_list_search_keeps_store_scope_for_store_owner(): void
    {
        $owner = $this->createStoreOwner([], $this->store);
        $visibleCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'name' => '同名客户',
        ]);
        $hiddenCustomer = Customer::factory()->create([
            'store_id' => $this->otherStore->id,
            'name' => '同名客户',
        ]);
        $visible = $this->createPayment([
            'store_id' => $this->store->id,
            'customer_id' => $visibleCustomer->id,
            'payment_number' => 'VISIBLE-001',
        ]);
        $this->createPayment([
            'store_id' => $this->otherStore->id,
            'customer_id' => $hiddenCustomer->id,
            'payment_number' => 'HIDDEN-001',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/payments?search=同名客户');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $visible->id);
    }

    public function test_payment_list_filters_by_allocation_status(): void
    {
        $owner = $this->createStoreOwner([], $this->store);
        $unallocated = $this->createPayment(['amount' => 500.00, 'allocated_amount' => 200.00]);
        $allocated = $this->createPayment(['amount' => 300.00, 'allocated_amount' => 300.00]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/payments?store_id='.$this->store->id.'&allocation_status=unallocated')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $unallocated->id);

        $this->getJson('/api/payments?store_id='.$this->store->id.'&allocation_status=allocated')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $allocated->id);
    }

    public function test_payment_list_filters_by_amount_and_inclusive_end_date(): void
    {
        $owner = $this->createStoreOwner([], $this->store);
        $inside = $this->createPayment([
            'amount' => 600.00,
            'created_at' => '2026-06-05 23:59:59',
            'updated_at' => '2026-06-05 23:59:59',
        ]);
        $this->createPayment([
            'amount' => 99.99,
            'created_at' => '2026-06-05 12:00:00',
            'updated_at' => '2026-06-05 12:00:00',
        ]);
        $this->createPayment([
            'amount' => 700.00,
            'created_at' => '2026-06-06 00:00:00',
            'updated_at' => '2026-06-06 00:00:00',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/payments?store_id='.$this->store->id.'&start_date=2026-06-05&end_date=2026-06-05&min_amount=100&max_amount=650')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $inside->id);
    }

    public function test_payment_list_sorts_by_unallocated_amount_and_falls_back_to_desc_direction(): void
    {
        $owner = $this->createStoreOwner([], $this->store);
        $small = $this->createPayment(['amount' => 500.00, 'allocated_amount' => 450.00]);
        $large = $this->createPayment(['amount' => 800.00, 'allocated_amount' => 100.00]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/payments?store_id='.$this->store->id.'&sort_by=unallocated_amount&sort_dir=sideways')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.id', $large->id)
            ->assertJsonPath('data.data.1.id', $small->id);
    }

    public function test_payment_list_rejects_invalid_filter_parameters(): void
    {
        $owner = $this->createStoreOwner([], $this->store);

        Sanctum::actingAs($owner);

        $cases = [
            [['start_date' => 'not-a-date'], 'start_date'],
            [['start_date' => '2026-06-07', 'end_date' => '2026-06-06'], 'end_date'],
            [['allocation_status' => 'pending'], 'allocation_status'],
            [['min_amount' => 'free'], 'min_amount'],
            [['min_amount' => '100', 'max_amount' => '50'], 'max_amount'],
        ];

        foreach ($cases as [$params, $field]) {
            $query = http_build_query(array_merge(['store_id' => $this->store->id], $params));

            $this->getJson('/api/payments?'.$query)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_payment_summary_returns_store_wide_totals(): void
    {
        $owner = $this->createStoreOwner([], $this->store);
        $this->createPayment([
            'amount' => 100.00,
            'allocated_amount' => 100.00,
            'created_at' => '2026-06-07 09:00:00',
            'updated_at' => '2026-06-07 09:00:00',
        ]);
        $this->createPayment([
            'amount' => 300.00,
            'allocated_amount' => 50.00,
            'created_at' => '2026-06-06 09:00:00',
            'updated_at' => '2026-06-06 09:00:00',
        ]);
        $this->createPayment([
            'amount' => 200.00,
            'allocated_amount' => 200.00,
            'created_at' => '2026-06-05 09:00:00',
            'updated_at' => '2026-06-05 09:00:00',
        ]);
        $this->createPayment([
            'store_id' => $this->otherStore->id,
            'amount' => 900.00,
            'allocated_amount' => 0.00,
            'created_at' => '2026-06-07 09:00:00',
            'updated_at' => '2026-06-07 09:00:00',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/payments/summary?store_id='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('data.today_collected_amount', '100.00')
            ->assertJsonPath('data.unallocated_amount', '250.00')
            ->assertJsonPath('data.allocation_completion_rate', 0.5833);
    }

    public function test_payment_summary_rejects_store_outside_user_scope(): void
    {
        $owner = $this->createStoreOwner([], $this->store);

        Sanctum::actingAs($owner);

        $this->getJson('/api/payments/summary?store_id='.$this->otherStore->id)
            ->assertForbidden();
    }

    private function assertPaymentSearchReturns(string $search, array $expectedIds): void
    {
        $response = $this->getJson('/api/payments?store_id='.$this->store->id.'&search='.urlencode($search));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', count($expectedIds));

        $actualIds = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing($expectedIds, $actualIds);
    }

    private function createPayment(array $attributes = []): Payment
    {
        $customerId = $attributes['customer_id'] ?? Customer::factory()->create([
            'store_id' => $attributes['store_id'] ?? $this->store->id,
        ])->id;
        $customer = Customer::findOrFail($customerId);

        return Payment::factory()->create(array_merge([
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'amount' => 500.00,
            'allocated_amount' => 0.00,
            'payment_method' => 'cash',
            'reference_number' => null,
            'remarks' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
