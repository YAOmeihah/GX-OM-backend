<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionBaselineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $storeOwner;

    private User $storeStaff;

    private Store $storeA;

    private Store $storeB;

    private Customer $customerA;

    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create system roles
        $adminRole = Role::firstOrCreate(['slug' => 'admin', 'name' => '系统管理员']);
        $storeOwnerRole = Role::firstOrCreate(['slug' => 'store_owner', 'name' => '店长']);
        $storeStaffRole = Role::firstOrCreate(['slug' => 'store_staff', 'name' => '店员']);

        // Admin user
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        // Stores
        $this->storeA = Store::factory()->create(['name' => 'Store A']);
        $this->storeB = Store::factory()->create(['name' => 'Store B']);

        // Customers (each in a different store)
        $this->customerA = Customer::factory()->create(['store_id' => $this->storeA->id]);
        $this->customerB = Customer::factory()->create(['store_id' => $this->storeB->id]);

        // Store owner — owns store A
        $this->storeOwner = User::factory()->create();
        $this->storeOwner->roles()->attach($storeOwnerRole);
        $this->storeOwner->stores()->attach($this->storeA->id);

        // Store staff — assigned to store A
        $this->storeStaff = User::factory()->create();
        $this->storeStaff->roles()->attach($storeStaffRole);
        $this->storeStaff->stores()->attach($this->storeA->id);
    }

    public function test_admin_can_access_all_stores(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(200);

        $this->getJson("/api/customers/{$this->customerB->id}")
            ->assertStatus(200);
    }

    public function test_store_owner_can_access_own_store_customers(): void
    {
        Sanctum::actingAs($this->storeOwner);

        $this->getJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(200);
    }

    public function test_store_owner_cannot_access_other_store_customers(): void
    {
        Sanctum::actingAs($this->storeOwner);

        $this->getJson("/api/customers/{$this->customerB->id}")
            ->assertStatus(403);
    }

    public function test_store_staff_cannot_access_other_store_customers(): void
    {
        Sanctum::actingAs($this->storeStaff);

        $this->getJson("/api/customers/{$this->customerB->id}")
            ->assertStatus(403);
    }

    public function test_removing_store_access_denies_permission(): void
    {
        // Baseline: store owner can access their store's customer
        Sanctum::actingAs($this->storeOwner);

        $this->getJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(200);

        // Detach the user from the store, removing their access
        $this->storeOwner->stores()->detach($this->storeA->id);

        // Now the same request must be denied
        $this->getJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_delete_customer(): void
    {
        // Customers with no invoices/payments can be deleted by admin
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(200);
    }

    public function test_store_owner_cannot_delete_customer(): void
    {
        // Only admin can delete customers
        Sanctum::actingAs($this->storeOwner);

        $this->deleteJson("/api/customers/{$this->customerA->id}")
            ->assertStatus(403);
    }

    public function test_customer_policy_enforces_store_bound_access(): void
    {
        // Directly test the Policy for store owner
        $this->assertTrue(
            $this->storeOwner->can('view', $this->customerA),
            'Store owner should be able to view their own store\'s customer'
        );

        $this->assertFalse(
            $this->storeOwner->can('view', $this->customerB),
            'Store owner should NOT be able to view another store\'s customer'
        );

        $this->assertTrue(
            $this->storeOwner->can('update', $this->customerA),
            'Store owner should be able to update their own store\'s customer'
        );

        $this->assertFalse(
            $this->storeOwner->can('update', $this->customerB),
            'Store owner should NOT be able to update another store\'s customer'
        );

        $this->assertFalse(
            $this->storeOwner->can('delete', $this->customerA),
            'Store owner should NOT be able to delete any customer'
        );

        // Admin can do everything
        $this->assertTrue(
            $this->admin->can('view', $this->customerA),
            'Admin should be able to view any customer'
        );

        $this->assertTrue(
            $this->admin->can('view', $this->customerB),
            'Admin should be able to view any customer'
        );

        $this->assertTrue(
            $this->admin->can('update', $this->customerA),
            'Admin should be able to update any customer'
        );

        $this->assertTrue(
            $this->admin->can('delete', $this->customerA),
            'Admin should be able to delete any customer'
        );
    }

    public function test_store_staff_cannot_batch_auto_allocate_store_payments(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'amount' => 100,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'amount' => 100,
            'allocated_amount' => 0,
            'received_by' => $this->storeStaff->id,
        ]);

        Sanctum::actingAs($this->storeStaff);

        $this->postJson('/api/payments/batch-auto-allocate', [
            'payment_ids' => [$payment->id],
            'store_id' => $this->storeA->id,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_non_admin_user_endpoint_returns_computed_store_manager_flag(): void
    {
        Sanctum::actingAs($this->storeOwner);

        $this->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('data.stores.0.id', $this->storeA->id)
            ->assertJsonPath('data.stores.0.is_manager', true);
    }

    public function test_admin_can_delete_store_without_business_associations(): void
    {
        $store = Store::factory()->create();

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/stores/{$store->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('stores', [
            'id' => $store->id,
        ]);
    }
}
