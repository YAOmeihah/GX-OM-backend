<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use App\Models\User;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Policies\PaymentPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\CustomerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 测试授权策略 (Policy) 类
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    protected User $admin;
    protected User $storeOwner;
    protected User $storeStaff;
    protected User $otherStoreOwner;
    protected Store $store;
    protected Store $otherStore;
    protected Customer $customer;
    protected Invoice $invoice;
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRolesExist();

        // 创建门店
        $this->store = Store::factory()->create(['name' => '测试门店A']);
        $this->otherStore = Store::factory()->create(['name' => '测试门店B']);

        // 创建用户
        $this->admin = $this->createAdmin();
        $this->storeOwner = $this->createStoreOwner([], $this->store);
        $this->storeStaff = $this->createStoreStaff([], $this->store);
        $this->otherStoreOwner = $this->createStoreOwner([], $this->otherStore);

        // 创建客户
        $this->customer = Customer::factory()->create();

        // 创建账单
        $this->invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'paid_amount' => 0,
            'status' => 'unpaid'
        ]);

        // 创建还款
        $this->payment = Payment::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 800.00,
            'received_by' => $this->storeOwner->id
        ]);
    }

    // ===================== PaymentPolicy 测试 =====================

    /** @test */
    public function admin_can_view_any_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertTrue($policy->viewAny($this->admin));
    }

    /** @test */
    public function store_owner_can_view_own_store_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertTrue($policy->view($this->storeOwner, $this->payment));
    }

    /** @test */
    public function store_owner_cannot_view_other_store_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertFalse($policy->view($this->otherStoreOwner, $this->payment));
    }

    /** @test */
    public function admin_can_delete_any_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertTrue($policy->delete($this->admin, $this->payment));
    }

    /** @test */
    public function store_owner_can_delete_own_store_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertTrue($policy->delete($this->storeOwner, $this->payment));
    }

    /** @test */
    public function store_staff_cannot_delete_payment()
    {
        $policy = new PaymentPolicy();
        $this->assertFalse($policy->delete($this->storeStaff, $this->payment));
    }

    /** @test */
    public function store_owner_can_auto_allocate()
    {
        $policy = new PaymentPolicy();
        $this->assertTrue($policy->autoAllocate($this->storeOwner, $this->payment));
    }

    /** @test */
    public function store_staff_cannot_auto_allocate()
    {
        $policy = new PaymentPolicy();
        $this->assertFalse($policy->autoAllocate($this->storeStaff, $this->payment));
    }

    // ===================== InvoicePolicy 测试 =====================

    /** @test */
    public function admin_can_view_any_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertTrue($policy->viewAny($this->admin));
    }

    /** @test */
    public function store_owner_can_view_own_store_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertTrue($policy->view($this->storeOwner, $this->invoice));
    }

    /** @test */
    public function store_owner_cannot_view_other_store_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertFalse($policy->view($this->otherStoreOwner, $this->invoice));
    }

    /** @test */
    public function admin_can_delete_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertTrue($policy->delete($this->admin, $this->invoice));
    }

    /** @test */
    public function store_owner_can_delete_own_store_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertTrue($policy->delete($this->storeOwner, $this->invoice));
    }

    /** @test */
    public function store_staff_cannot_delete_invoice()
    {
        $policy = new InvoicePolicy();
        $this->assertFalse($policy->delete($this->storeStaff, $this->invoice));
    }

    // ===================== CustomerPolicy 测试 =====================

    /** @test */
    public function anyone_can_view_customers()
    {
        $policy = new CustomerPolicy();
        $this->assertTrue($policy->viewAny($this->storeStaff));
    }

    /** @test */
    public function anyone_can_view_customer_details()
    {
        $policy = new CustomerPolicy();
        $this->assertTrue($policy->view($this->storeStaff, $this->customer));
    }

    /** @test */
    public function anyone_can_create_customer()
    {
        $policy = new CustomerPolicy();
        $this->assertTrue($policy->create($this->storeStaff));
    }

    /** @test */
    public function anyone_can_update_customer()
    {
        $policy = new CustomerPolicy();
        $this->assertTrue($policy->update($this->storeStaff, $this->customer));
    }

    /** @test */
    public function only_admin_can_delete_customer()
    {
        $policy = new CustomerPolicy();
        $this->assertTrue($policy->delete($this->admin, $this->customer));
        $this->assertFalse($policy->delete($this->storeOwner, $this->customer));
        $this->assertFalse($policy->delete($this->storeStaff, $this->customer));
    }
}
