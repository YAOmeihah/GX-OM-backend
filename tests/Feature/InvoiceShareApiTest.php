<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceShareToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class InvoiceShareApiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    protected User $admin;

    protected User $storeOwner;

    protected Store $store;

    protected Store $store2;

    protected Customer $customer;

    protected Invoice $invoice1;

    protected Invoice $invoice2;

    protected Invoice $invoice3;

    protected function setUp(): void
    {
        parent::setUp();

        // 确保基础角色存在
        $this->ensureRolesExist();

        // 创建测试门店
        $this->store = Store::factory()->create(['name' => '贵献花卉', 'phone' => '13800138000']);
        $this->store2 = Store::factory()->create(['name' => '其他门店']);

        // 创建测试用户
        $this->admin = $this->createAdmin();
        $this->storeOwner = $this->createStoreOwner([], $this->store);

        // 创建测试客户
        $this->customer = Customer::factory()->create(['name' => '张三', 'phone' => '13912345678', 'store_id' => $this->store->id]);

        // 创建测试账单
        $this->invoice1 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'paid_amount' => 300.00,
            'status' => 'partially_paid',
            'created_by' => $this->storeOwner->id,
        ]);

        $this->invoice2 = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 500.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'created_by' => $this->storeOwner->id,
        ]);

        // 不同门店的账单
        $this->invoice3 = Invoice::factory()->create([
            'store_id' => $this->store2->id,
            'customer_id' => $this->customer->id,
            'amount' => 200.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'created_by' => $this->admin->id,
        ]);
    }

    /** @test */
    public function it_can_create_share_link_for_single_invoice()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson('/api/invoices/share-link', [
            'invoice_ids' => [$this->invoice1->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'share_token',
                    'mini_program_path',
                    'expires_at',
                ],
            ]);

        $this->assertDatabaseHas('invoice_share_tokens', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
        ]);
    }

    /** @test */
    public function it_can_create_share_link_for_multiple_invoices()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson('/api/invoices/share-link', [
            'invoice_ids' => [$this->invoice1->id, $this->invoice2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // 验证令牌包含两个账单ID
        $token = InvoiceShareToken::where('created_by', $this->storeOwner->id)->first();
        $this->assertCount(2, $token->invoice_ids);
    }

    /** @test */
    public function it_rejects_invoices_from_different_stores()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/invoices/share-link', [
            'invoice_ids' => [$this->invoice1->id, $this->invoice3->id],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '所选账单必须属于同一门店',
            ]);
    }

    /** @test */
    public function it_rejects_unauthenticated_share_link_requests()
    {
        $response = $this->postJson('/api/invoices/share-link', [
            'invoice_ids' => [$this->invoice1->id],
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_access_public_bill_with_valid_token()
    {
        // 创建分享令牌
        $token = InvoiceShareToken::create([
            'token' => InvoiceShareToken::generateToken(),
            'invoice_ids' => [$this->invoice1->id],
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
            'expires_at' => now()->addMonths(3),
        ]);

        // 无需登录即可访问
        $response = $this->getJson("/api/public/bills/{$token->token}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'mode' => 'single',
                    'store' => [
                        'name' => '贵献花卉',
                    ],
                    'customer' => [
                        'name' => '张三',
                        'phone' => '139****5678', // 脱敏后的电话
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'invoice' => [
                        'id',
                        'invoice_number',
                        'amount',
                        'paid_amount',
                        'remaining',
                        'status',
                        'status_text',
                        'items',
                    ],
                ],
            ]);

        // 验证访问日志
        $this->assertDatabaseHas('invoice_share_token_logs', [
            'token_id' => $token->id,
        ]);
    }

    /** @test */
    public function it_returns_multiple_mode_for_multiple_invoices()
    {
        // 创建包含多个账单的分享令牌
        $token = InvoiceShareToken::create([
            'token' => InvoiceShareToken::generateToken(),
            'invoice_ids' => [$this->invoice1->id, $this->invoice2->id],
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
            'expires_at' => now()->addMonths(3),
        ]);

        $response = $this->getJson("/api/public/bills/{$token->token}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'mode' => 'multiple',
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'total_count',
                        'total_amount',
                        'total_paid',
                        'total_remaining',
                    ],
                    'invoices',
                ],
            ]);

        // 验证汇总数据
        $data = $response->json('data');
        $this->assertEquals(2, $data['summary']['total_count']);
        $this->assertEquals('1500.00', $data['summary']['total_amount']); // 1000 + 500
        $this->assertEquals('300.00', $data['summary']['total_paid']); // 只有invoice1有300
    }

    /** @test */
    public function it_rejects_expired_token()
    {
        // 创建已过期的令牌
        $token = InvoiceShareToken::create([
            'token' => InvoiceShareToken::generateToken(),
            'invoice_ids' => [$this->invoice1->id],
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
            'expires_at' => now()->subDay(), // 已过期
        ]);

        $response = $this->getJson("/api/public/bills/{$token->token}");

        $response->assertStatus(410) // 410 Gone
            ->assertJson([
                'success' => false,
                'message' => '分享链接已过期',
            ]);
    }

    /** @test */
    public function it_rejects_invalid_token()
    {
        $response = $this->getJson('/api/public/bills/invalid_token_12345');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => '分享链接不存在',
            ]);
    }

    /** @test */
    public function it_masks_customer_phone_correctly()
    {
        $token = InvoiceShareToken::create([
            'token' => InvoiceShareToken::generateToken(),
            'invoice_ids' => [$this->invoice1->id],
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
            'expires_at' => now()->addMonths(3),
        ]);

        $response = $this->getJson("/api/public/bills/{$token->token}");

        $response->assertStatus(200);
        $this->assertEquals('139****5678', $response->json('data.customer.phone'));
    }

    /** @test */
    public function it_logs_access_with_ip_and_user_agent()
    {
        $token = InvoiceShareToken::create([
            'token' => InvoiceShareToken::generateToken(),
            'invoice_ids' => [$this->invoice1->id],
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->storeOwner->id,
            'expires_at' => now()->addMonths(3),
        ]);

        $this->getJson("/api/public/bills/{$token->token}", [
            'User-Agent' => 'Mozilla/5.0 WeChat MiniProgram',
        ]);

        $this->assertDatabaseHas('invoice_share_token_logs', [
            'token_id' => $token->id,
        ]);

        $log = $token->accessLogs()->first();
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->accessed_at);
    }

    /** @test */
    public function it_respects_custom_expiry_hours()
    {
        Sanctum::actingAs($this->storeOwner);

        $response = $this->postJson('/api/invoices/share-link', [
            'invoice_ids' => [$this->invoice1->id],
            'expires_hours' => 24, // 1天
        ]);

        $response->assertStatus(200);

        $token = InvoiceShareToken::where('created_by', $this->storeOwner->id)->first();
        $this->assertTrue($token->expires_at->lt(now()->addHours(25)));
        $this->assertTrue($token->expires_at->gt(now()->addHours(23)));
    }
}
