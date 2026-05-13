<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 账单审计日志测试
 *
 * 测试基于 line_uid 的明细增删改识别和结构化审计日志
 */
class InvoiceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Store $store;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建测试数据
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->store = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    }

    /**
     * 测试：新增明细时生成正确的审计日志
     */
    public function test_adding_items_creates_audit_log_with_added_changes()
    {
        // 创建初始账单（只有一条明细）
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $originalItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '原始项目',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // 更新账单，新增一条明细
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $originalItem->line_uid,
                    'item_name' => '原始项目',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
                [
                    'item_name' => '新增项目',
                    'quantity' => 2,
                    'unit_price' => 50,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证审计日志
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->change_payload);
        $this->assertEquals('invoice', $log->change_payload['domain']);
        $this->assertEquals(1, $log->change_payload['stats']['added_count']);
        $this->assertCount(1, $log->change_payload['item_changes']['added']);
        $this->assertEquals('新增项目', $log->change_payload['item_changes']['added'][0]['item_name']);
    }

    /**
     * 测试：删除明细时生成正确的审计日志
     */
    public function test_removing_items_creates_audit_log_with_removed_changes()
    {
        // 创建初始账单（两条明细）
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $item1 = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '项目1',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        $item2 = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '项目2',
            'quantity' => 2,
            'unit_price' => 50,
        ]);

        // 更新账单，删除第二条明细
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $item1->line_uid,
                    'item_name' => '项目1',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证审计日志
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->change_payload);
        $this->assertEquals(1, $log->change_payload['stats']['removed_count']);
        $this->assertCount(1, $log->change_payload['item_changes']['removed']);
        $this->assertEquals('项目2', $log->change_payload['item_changes']['removed'][0]['item_name']);
    }

    /**
     * 测试：修改明细时生成正确的审计日志
     */
    public function test_updating_items_creates_audit_log_with_updated_changes()
    {
        // 创建初始账单
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '原始名称',
            'item_description' => '原始描述',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // 更新明细的名称和数量
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $item->line_uid,
                    'item_name' => '新名称',
                    'item_description' => '原始描述',
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证审计日志
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->change_payload);
        $this->assertEquals(1, $log->change_payload['stats']['updated_count']);
        $this->assertCount(1, $log->change_payload['item_changes']['updated']);

        $updatedItem = $log->change_payload['item_changes']['updated'][0];
        $this->assertArrayHasKey('field_changes', $updatedItem);
        $this->assertArrayHasKey('item_name', $updatedItem['field_changes']);
        $this->assertArrayHasKey('quantity', $updatedItem['field_changes']);
        $this->assertEquals('原始名称', $updatedItem['field_changes']['item_name']['old']);
        $this->assertEquals('新名称', $updatedItem['field_changes']['item_name']['new']);
    }

    /**
     * 测试：清空可空字段时正确识别为修改
     */
    public function test_clearing_nullable_fields_is_tracked_as_update()
    {
        // 创建初始账单
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '项目名称',
            'item_description' => '有描述',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // 清空描述字段
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $item->line_uid,
                    'item_name' => '项目名称',
                    'item_description' => null,  // 明确传 null
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证数据库中描述已清空
        $item->refresh();
        $this->assertNull($item->item_description);

        // 验证审计日志记录了这次修改
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->change_payload);
        $this->assertEquals(1, $log->change_payload['stats']['updated_count']);

        $updatedItem = $log->change_payload['item_changes']['updated'][0];
        $this->assertArrayHasKey('item_description', $updatedItem['field_changes']);
        $this->assertEquals('有描述', $updatedItem['field_changes']['item_description']['old']);
        $this->assertNull($updatedItem['field_changes']['item_description']['new']);
    }

    /**
     * 测试：混合编辑（新增+删除+修改）
     */
    public function test_mixed_item_changes_are_tracked_correctly()
    {
        // 创建初始账单（两条明细）
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $item1 = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '保留项目',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        $item2 = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '删除项目',
            'quantity' => 2,
            'unit_price' => 50,
        ]);

        // 混合操作：保留并修改 item1，删除 item2，新增 item3
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $item1->line_uid,
                    'item_name' => '修改后的项目',  // 修改
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
                [
                    'item_name' => '新增项目',  // 新增
                    'quantity' => 3,
                    'unit_price' => 30,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证审计日志
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->change_payload);
        $this->assertEquals(1, $log->change_payload['stats']['added_count']);
        $this->assertEquals(1, $log->change_payload['stats']['removed_count']);
        $this->assertEquals(1, $log->change_payload['stats']['updated_count']);
        $this->assertEquals(3, $log->change_payload['stats']['total_change_count']);
    }

    /**
     * 测试：重复 line_uid 被拒绝
     */
    public function test_duplicate_line_uid_in_request_is_rejected()
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);

        $duplicateUid = '12345678-1234-1234-1234-123456789012';

        // 尝试提交重复的 line_uid
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'items' => [
                [
                    'line_uid' => $duplicateUid,
                    'item_name' => '项目1',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
                [
                    'line_uid' => $duplicateUid,  // 重复
                    'item_name' => '项目2',
                    'quantity' => 2,
                    'unit_price' => 50,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.1.line_uid');
    }

    /**
     * 测试：change_payload 结构完整性
     */
    public function test_change_payload_structure_is_complete()
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'item_name' => '原始项目',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // 执行更新
        $response = $this->actingAs($this->admin)->putJson("/api/invoices/{$invoice->id}", [
            'description' => '更新描述',
            'items' => [
                [
                    'line_uid' => $item->line_uid,
                    'item_name' => '修改后的项目',
                    'quantity' => 2,
                    'unit_price' => 150,
                ],
            ],
        ]);

        $response->assertOk();

        // 验证 change_payload 结构
        $log = AuditLog::where('auditable_id', $invoice->id)
            ->where('auditable_type', Invoice::class)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $payload = $log->change_payload;
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('schema_version', $payload);
        $this->assertArrayHasKey('domain', $payload);
        $this->assertArrayHasKey('event', $payload);
        $this->assertArrayHasKey('target', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('stats', $payload);
        $this->assertArrayHasKey('basic_changes', $payload);
        $this->assertArrayHasKey('item_changes', $payload);
        $this->assertArrayHasKey('financial_effect', $payload);

        // 验证 stats 结构
        $this->assertArrayHasKey('added_count', $payload['stats']);
        $this->assertArrayHasKey('removed_count', $payload['stats']);
        $this->assertArrayHasKey('updated_count', $payload['stats']);
        $this->assertArrayHasKey('total_change_count', $payload['stats']);

        // 验证 financial_effect 结构
        $this->assertArrayHasKey('old_total', $payload['financial_effect']);
        $this->assertArrayHasKey('new_total', $payload['financial_effect']);
        $this->assertArrayHasKey('delta', $payload['financial_effect']);
    }
}

