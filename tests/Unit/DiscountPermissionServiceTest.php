<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\DiscountPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class DiscountPermissionServiceTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private DiscountPermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRolesExist();
        $this->service = new DiscountPermissionService;
    }

    public function test_admin_can_approve_any_discount_amount(): void
    {
        $admin = $this->createAdmin();

        $this->assertTrue($this->service->canApproveAmount($admin, 'write_off', 999999.99));
        $this->assertTrue($this->service->canApproveDiscount($admin, Store::factory()->create()->id, 'write_off', 999999.99));
    }

    public function test_store_owner_must_belong_to_store_to_approve_discount(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $owner = $this->createStoreOwner([], $store);

        $this->assertTrue($this->service->canApproveDiscount($owner, $store->id, 'discount', 10));
        $this->assertFalse($this->service->canApproveDiscount($owner, $otherStore->id, 'discount', 10));
    }

    public function test_store_staff_amount_limit_uses_auto_discount_cap(): void
    {
        config([
            'payment.discount_types.discount.max_amount' => 500,
            'payment.discount_types.discount.approval_roles' => ['store_staff'],
            'payment.auto_discount.max_amount' => 100,
        ]);

        $store = Store::factory()->create();
        $staff = $this->createStoreStaff([], $store);

        $this->assertTrue($this->service->canApproveDiscount($staff, $store->id, 'discount', 100));
        $this->assertFalse($this->service->canApproveDiscount($staff, $store->id, 'discount', 101));
    }

    public function test_requires_approval_uses_type_and_auto_limit(): void
    {
        config([
            'payment.discount_types.discount.requires_approval' => false,
            'payment.auto_discount.max_amount' => 100,
        ]);

        $this->assertFalse($this->service->requiresApproval('discount', 100));
        $this->assertTrue($this->service->requiresApproval('discount', 101));
        $this->assertTrue($this->service->requiresApproval('missing-type', 1));
    }
}
