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

/**
 * 测试客户数据的门店权限控制
 * 确保用户只能看到其有权限访问的门店的数据
 */
class CustomerStorePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $storeOwnerA;

    protected User $storeOwnerB;

    protected User $storeStaffA;

    protected Store $storeA;

    protected Store $storeB;

    protected Customer $customer;

    protected Invoice $invoiceA;

    protected Invoice $invoiceB;

    protected Payment $paymentA;

    protected Payment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建角色
        $adminRole = Role::firstOrCreate(['slug' => 'admin', 'name' => '系统管理员']);
        $storeOwnerRole = Role::firstOrCreate(['slug' => 'store_owner', 'name' => '店长']);
        $storeStaffRole = Role::firstOrCreate(['slug' => 'store_staff', 'name' => '店员']);

        // 创建用户并分配角色
        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach($adminRole);

        $this->storeOwnerA = User::factory()->create();
        $this->storeOwnerA->roles()->attach($storeOwnerRole);

        $this->storeOwnerB = User::factory()->create();
        $this->storeOwnerB->roles()->attach($storeOwnerRole);

        $this->storeStaffA = User::factory()->create();
        $this->storeStaffA->roles()->attach($storeStaffRole);

        // 创建门店
        $this->storeA = Store::factory()->create(['name' => '门店A']);
        $this->storeB = Store::factory()->create(['name' => '门店B']);

        // 关联用户到门店
        $this->storeOwnerA->stores()->attach($this->storeA->id);
        $this->storeStaffA->stores()->attach($this->storeA->id);
        $this->storeOwnerB->stores()->attach($this->storeB->id);

        // 创建客户（关联到门店A）
        $this->customer = Customer::factory()->create(['name' => '测试客户', 'store_id' => $this->storeA->id]);

        // 创建账单（分别在两个门店）
        $this->invoiceA = Invoice::factory()->create([
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->invoiceB = Invoice::factory()->create([
            'store_id' => $this->storeB->id,
            'customer_id' => $this->customer->id,
            'amount' => 2000.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // 创建还款记录（分别在两个门店）
        $this->paymentA = Payment::factory()->create([
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customer->id,
            'amount' => 500.00,
            'received_by' => $this->storeOwnerA->id,
        ]);

        $this->paymentB = Payment::factory()->create([
            'store_id' => $this->storeB->id,
            'customer_id' => $this->customer->id,
            'amount' => 800.00,
            'received_by' => $this->storeOwnerB->id,
        ]);
    }

    /** @test */
    public function admin_can_see_all_store_data_in_customer_debt()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 管理员应该能看到所有门店的数据
        $this->assertCount(2, $data['unpaid_invoices']);
        $this->assertEquals(3000.00, $data['traditional_debt']); // 1000 + 2000

        // 检查账单是否包含两个门店的数据
        $storeIds = collect($data['unpaid_invoices'])->pluck('store_id')->toArray();
        $this->assertContains($this->storeA->id, $storeIds);
        $this->assertContains($this->storeB->id, $storeIds);
    }

    /** @test */
    public function store_owner_can_only_see_own_store_data()
    {
        Sanctum::actingAs($this->storeOwnerA);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 店长A只能看到门店A的数据
        $this->assertCount(1, $data['unpaid_invoices']);
        $this->assertEquals(1000.00, $data['traditional_debt']); // 只有门店A的1000

        // 检查账单只包含门店A的数据
        $invoice = $data['unpaid_invoices'][0];
        $this->assertEquals($this->storeA->id, $invoice['store_id']);
        $this->assertEquals($this->invoiceA->id, $invoice['id']);
    }

    /** @test */
    public function store_staff_can_only_see_own_store_data()
    {
        Sanctum::actingAs($this->storeStaffA);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 店员A只能看到门店A的数据
        $this->assertCount(1, $data['unpaid_invoices']);
        $this->assertEquals(1000.00, $data['traditional_debt']);

        // 检查可访问门店列表
        $this->assertContains($this->storeA->id, $data['accessible_stores']);
        $this->assertNotContains($this->storeB->id, $data['accessible_stores']);
    }

    /** @test */
    public function user_cannot_access_unauthorized_store_data()
    {
        Sanctum::actingAs($this->storeOwnerA);

        // 尝试访问门店B的数据
        $response = $this->getJson("/api/customers/{$this->customer->id}/debt?store_id={$this->storeB->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => '您没有权限访问该门店的数据',
            ]);
    }

    /** @test */
    public function customer_show_respects_store_permissions()
    {
        Sanctum::actingAs($this->storeOwnerA);

        $response = $this->getJson("/api/customers/{$this->customer->id}");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 检查只返回门店A的账单和还款
        $this->assertCount(1, $data['invoices']);
        $this->assertCount(1, $data['payments']);

        $this->assertEquals($this->invoiceA->id, $data['invoices'][0]['id']);
        $this->assertEquals($this->paymentA->id, $data['payments'][0]['id']);
    }

    /** @test */
    public function user_without_store_access_gets_forbidden()
    {
        // 创建一个没有门店权限的用户
        $storeStaffRole = Role::where('slug', 'store_staff')->first();
        $userWithoutStores = User::factory()->create();
        $userWithoutStores->roles()->attach($storeStaffRole);

        Sanctum::actingAs($userWithoutStores);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => '您没有权限访问任何门店的数据',
            ]);
    }

    /** @test */
    public function specific_store_query_returns_only_that_store_data()
    {
        Sanctum::actingAs($this->adminUser);

        // 查询特定门店的数据
        $response = $this->getJson("/api/customers/{$this->customer->id}/debt?store_id={$this->storeA->id}");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 应该只返回门店A的数据
        $this->assertCount(1, $data['unpaid_invoices']);
        $this->assertEquals(1000.00, $data['traditional_debt']);
        $this->assertEquals($this->storeA->id, $data['unpaid_invoices'][0]['store_id']);
    }

    /** @test */
    public function multi_store_debt_info_is_provided_when_no_specific_store()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("/api/customers/{$this->customer->id}/debt");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 应该提供多门店汇总信息
        $this->assertArrayHasKey('store_debt_info', $data);
        $this->assertEquals(2, $data['store_debt_info']['store_count']);
        $this->assertEquals(2, $data['store_debt_info']['total_invoices']);
        $this->assertEquals(3000.00, $data['store_debt_info']['total_amount']);
    }
}
