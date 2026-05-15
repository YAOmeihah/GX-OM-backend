<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStoreMigrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_customer_fix_does_not_reuse_same_name_customer_when_identity_differs(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '张三',
            'phone' => '13800000001',
            'email' => 'a@example.com',
            'id_card' => 'ID-A',
        ]);

        $sameNameDifferentPerson = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '张三',
            'phone' => '13800000002',
            'email' => 'b@example.com',
            'id_card' => 'ID-B',
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $invoice->refresh();
        $payment->refresh();

        $this->assertNotSame($sameNameDifferentPerson->id, $invoice->customer_id);
        $this->assertNotSame($sameNameDifferentPerson->id, $payment->customer_id);
        $this->assertSame($invoice->customer_id, $payment->customer_id);

        $newCustomer = Customer::findOrFail($invoice->customer_id);
        $this->assertSame($storeB->id, $newCustomer->store_id);
        $this->assertStringStartsWith('张三', $newCustomer->name);
        $this->assertSame('13800000001', $newCustomer->phone);
        $this->assertSame('a@example.com', $newCustomer->email);
        $this->assertSame('ID-A', $newCustomer->id_card);
    }

    public function test_orphan_customer_fix_does_not_reuse_customer_when_identity_fields_conflict(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '李四',
            'phone' => '13800000003',
            'email' => 'li-original@example.com',
            'id_card' => 'ID-ORIGINAL',
        ]);

        $conflictingCustomer = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '李四',
            'phone' => '13800000003',
            'email' => 'li-other@example.com',
            'id_card' => 'ID-OTHER',
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $invoice->refresh();

        $this->assertNotSame($conflictingCustomer->id, $invoice->customer_id);

        $newCustomer = Customer::findOrFail($invoice->customer_id);
        $this->assertSame($storeB->id, $newCustomer->store_id);
        $this->assertSame('13800000003', $newCustomer->phone);
        $this->assertSame('li-original@example.com', $newCustomer->email);
        $this->assertSame('ID-ORIGINAL', $newCustomer->id_card);
    }

    public function test_orphan_customer_fix_reuses_existing_migration_suffix_clone_on_rerun(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '王五',
            'phone' => '13800000004',
            'email' => 'wang@example.com',
            'id_card' => 'ID-WANG',
        ]);

        Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '王五',
            'phone' => '13800000005',
            'email' => 'wang-other@example.com',
            'id_card' => 'ID-WANG-OTHER',
        ]);

        $existingClone = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '王五#迁移1',
            'phone' => '13800000004',
            'email' => 'wang@example.com',
            'id_card' => 'ID-WANG',
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $existingClone->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $invoice->refresh();
        $payment->refresh();

        $this->assertSame($existingClone->id, $invoice->customer_id);
        $this->assertSame($existingClone->id, $payment->customer_id);
        $this->assertSame(2, Customer::where('store_id', $storeB->id)->count());
    }

    public function test_orphan_customer_fix_reuses_existing_migration_suffix_clone_without_identity_on_rerun(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $createdAt = '2026-03-01 10:00:00';
        $updatedAt = '2026-03-02 10:00:00';

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '赵六',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '旧地址',
            'remarks' => '旧备注',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '赵六',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '其他地址',
            'remarks' => '其他备注',
        ]);

        $existingClone = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '赵六#迁移1',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '旧地址',
            'remarks' => '旧备注',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $existingClone->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $payment->refresh();

        $this->assertSame($existingClone->id, $payment->customer_id);
        $this->assertSame(2, Customer::where('store_id', $storeB->id)->count());
    }

    public function test_orphan_customer_fix_reuses_legacy_unsuffixed_no_identity_partial_clone_on_rerun(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $createdAt = '2026-03-03 10:00:00';
        $updatedAt = '2026-03-04 10:00:00';

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '钱七',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '钱七地址',
            'remarks' => '钱七备注',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $legacyClone = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '钱七',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '钱七地址',
            'remarks' => '钱七备注',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $invoice = Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $legacyClone->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $invoice->refresh();
        $payment->refresh();

        $this->assertSame($legacyClone->id, $invoice->customer_id);
        $this->assertSame($legacyClone->id, $payment->customer_id);
        $this->assertSame(1, Customer::where('store_id', $storeB->id)->count());
    }

    public function test_orphan_customer_fix_does_not_reuse_no_identity_suffix_clone_with_different_profile(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $createdAt = '2026-03-05 10:00:00';
        $updatedAt = '2026-03-06 10:00:00';

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '孙八',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '孙八地址',
            'remarks' => '孙八备注',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $differentProfileClone = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '孙八#迁移1',
            'phone' => null,
            'email' => null,
            'id_card' => null,
            'address' => '另一个地址',
            'remarks' => '另一个备注',
            'created_at' => '2026-03-07 10:00:00',
            'updated_at' => '2026-03-08 10:00:00',
        ]);

        Invoice::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $differentProfileClone->id,
            'amount' => 100,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $payment->refresh();

        $this->assertNotSame($differentProfileClone->id, $payment->customer_id);

        $newClone = Customer::findOrFail($payment->customer_id);
        $this->assertSame($storeB->id, $newClone->store_id);
        $this->assertSame('孙八#迁移2', $newClone->name);
        $this->assertSame('孙八地址', $newClone->address);
        $this->assertSame('孙八备注', $newClone->remarks);
        $this->assertSame(2, Customer::where('store_id', $storeB->id)->count());
    }

    public function test_orphan_customer_fix_does_not_treat_customer_name_like_wildcards_as_pattern(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $original = Customer::factory()->create([
            'store_id' => $storeA->id,
            'name' => '周_%',
            'phone' => '13800000006',
            'email' => 'wildcard-source@example.com',
            'id_card' => 'ID-WILDCARD',
        ]);

        $likeOvermatch = Customer::factory()->create([
            'store_id' => $storeB->id,
            'name' => '周XYZ#迁移1',
            'phone' => '13800000006',
            'email' => 'wildcard-source@example.com',
            'id_card' => 'ID-WILDCARD',
        ]);

        $payment = Payment::factory()->create([
            'store_id' => $storeB->id,
            'customer_id' => $original->id,
            'amount' => 100,
            'allocated_amount' => 0,
        ]);

        $migration = require database_path('migrations/2026_03_14_000001_fix_orphan_invoices_after_customer_store_migration.php');
        $migration->up();

        $payment->refresh();

        $this->assertNotSame($likeOvermatch->id, $payment->customer_id);

        $newClone = Customer::findOrFail($payment->customer_id);
        $this->assertSame($storeB->id, $newClone->store_id);
        $this->assertSame('周_%', $newClone->name);
    }
}
