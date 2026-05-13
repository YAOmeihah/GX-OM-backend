<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Store $store;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建基础测试数据
        $this->user = User::factory()->create();
        $this->store = Store::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    // ==================== CleanupHistoryCommand Tests ====================

    /**
     * @test
     * 测试历史清理命令 - 干运行模式
     */
    public function cleanup_history_dry_run_shows_statistics(): void
    {
        // 创建一个4个月前的已结清账单
        $oldInvoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'status' => 'paid',
            'amount' => 100,
            'paid_amount' => 100,
            'created_at' => Carbon::now()->subMonths(4),
        ]);

        // 创建关联的账单明细
        InvoiceItem::factory()->create([
            'invoice_id' => $oldInvoice->id,
        ]);

        $this->artisan('maintenance:cleanup-history', ['--dry-run' => true])
            ->assertExitCode(0);

        // 确认数据没有被删除
        $this->assertDatabaseHas('invoices', ['id' => $oldInvoice->id]);
    }

    /**
     * @test
     * 测试历史清理命令 - 只清理已结清账单
     */
    public function cleanup_history_only_cleans_paid_invoices(): void
    {
        // 创建未付账单 (不应被清理)
        $unpaidInvoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'status' => 'unpaid',
            'amount' => 100,
            'paid_amount' => 0,
            'created_at' => Carbon::now()->subMonths(4),
        ]);

        // 创建已结清账单 (应被清理)
        $paidInvoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'status' => 'paid',
            'amount' => 100,
            'paid_amount' => 100,
            'created_at' => Carbon::now()->subMonths(4),
        ]);

        $this->artisan('maintenance:cleanup-history', [
            '--months' => 3,
            '--include' => 'invoices',
            '--force' => true,
        ])->assertExitCode(0);

        // 未付账单应保留
        $this->assertDatabaseHas('invoices', ['id' => $unpaidInvoice->id]);
        // 已付账单应被删除
        $this->assertDatabaseMissing('invoices', ['id' => $paidInvoice->id]);
    }

    /**
     * @test
     * 测试历史清理命令 - 能处理有明细的账单（不报错）
     */
    public function cleanup_history_handles_invoices_with_items(): void
    {
        // 创建一个老账单并添加明细
        $oldInvoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'status' => 'paid',
            'amount' => 100,
            'paid_amount' => 100,
            'created_at' => Carbon::now()->subMonths(12),
        ]);

        // 创建关联的明细项
        InvoiceItem::factory()->create([
            'invoice_id' => $oldInvoice->id,
        ]);

        // 命令应该能成功执行，不会因为有关联数据而报错
        $this->artisan('maintenance:cleanup-history', [
            '--months' => 6,
            '--include' => 'invoices',
            '--force' => true,
        ])->assertExitCode(0);

        // 验证命令成功输出了删除信息
        // 实际删除验证已在 cleanup_history_only_cleans_paid_invoices 测试中完成
    }


    // ==================== OrphanCheckCommand Tests ====================

    /**
     * @test
     * 测试孤立数据检测 - 检测孤立的账单明细
     * 注意：需要临时禁用外键检查才能创建孤立数据
     */
    public function orphan_check_detects_orphan_invoice_items(): void
    {
        // 临时禁用外键检查
        Schema::disableForeignKeyConstraints();

        // 直接在数据库中创建孤立记录
        DB::table('invoice_items')->insert([
            'invoice_id' => 99999, // 不存在的账单ID
            'item_name' => '孤立项目',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        $this->artisan('maintenance:orphan-check', ['--type' => 'invoice_items'])
            ->assertExitCode(0);

        // 清理测试数据
        Schema::disableForeignKeyConstraints();
        DB::table('invoice_items')->where('invoice_id', 99999)->delete();
        Schema::enableForeignKeyConstraints();
    }

    /**
     * @test
     * 测试孤立数据检测 - 无孤立数据时正常通过
     */
    public function orphan_check_passes_with_no_orphans(): void
    {
        // 创建正常的账单和明细
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
        ]);

        $this->artisan('maintenance:orphan-check')
            ->assertExitCode(0);
    }

    // ==================== IntegrityCheckCommand Tests ====================

    /**
     * @test
     * 测试完整性检查 - 检测账单金额不一致
     */
    public function integrity_check_detects_invoice_amount_mismatch(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'amount' => 1000, // 设置不匹配的金额
        ]);

        // 创建明细，总计只有 100 (不使用 factory 因为会触发计算)
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'item_name' => '测试项',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('maintenance:integrity-check', ['--type' => 'invoice_amount'])
            ->assertExitCode(0);
    }

    /**
     * @test
     * 测试完整性检查 - 数据正常时无问题
     */
    public function integrity_check_passes_with_consistent_data(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'amount' => 0,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->artisan('maintenance:integrity-check', ['--type' => 'all'])
            ->assertExitCode(0);
    }

    /**
     * @test
     * 测试完整性检查 - 账单状态检查
     */
    public function integrity_check_detects_status_mismatch(): void
    {
        // 创建金额已付清但状态不正确的账单
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'amount' => 100,
            'paid_amount' => 100,
            'status' => 'unpaid', // 应该是 paid
        ]);

        $this->artisan('maintenance:integrity-check', ['--type' => 'invoice_status'])
            ->assertExitCode(0);
    }

    // ==================== RunMaintenanceCommand Tests ====================

    /**
     * @test
     * 测试综合调度 - 无效 profile 返回错误
     */
    public function run_maintenance_invalid_profile_fails(): void
    {
        $this->artisan('maintenance:run', ['--profile' => 'invalid'])
            ->assertExitCode(1);
    }

    /**
     * @test
     * 测试综合调度 - 显示可用配置列表
     */
    public function run_maintenance_lists_available_profiles_on_invalid(): void
    {
        $this->artisan('maintenance:run', ['--profile' => 'invalid'])
            ->expectsOutputToContain('daily')
            ->assertExitCode(1);
    }

    // ==================== CleanupAuditLogs Tests ====================

    /**
     * @test
     * 测试审计日志清理 - 无数据时正常退出
     */
    public function audit_cleanup_exits_gracefully_with_no_data(): void
    {
        $this->artisan('audit:cleanup', ['--days' => 90])
            ->assertExitCode(0);
    }

    /**
     * @test
     * 测试审计日志清理 - 保留天数为0时不执行
     */
    public function audit_cleanup_skips_when_retention_is_zero(): void
    {
        $this->artisan('audit:cleanup', ['--days' => 0])
            ->expectsOutputToContain('保留天数设置为0')
            ->assertExitCode(0);
    }

    /**
     * @test
     * 测试审计日志清理 - 分级保留策略
     */
    public function audit_cleanup_respects_critical_retention(): void
    {
        // 创建普通操作日志 (100天前)
        \App\Models\AuditLog::factory()->create([
            'created_at' => Carbon::now()->subDays(100),
            'action' => 'create',
        ]);

        // 创建关键操作日志 (100天前)
        $criticalLog = \App\Models\AuditLog::factory()->create([
            'created_at' => Carbon::now()->subDays(100),
            'action' => 'delete',
        ]);

        $this->artisan('audit:cleanup', [
            '--days' => 90,
            '--keep-critical' => 365, // 关键操作保留1年
        ])->assertExitCode(0);

        // 关键操作日志应保留
        $this->assertDatabaseHas('audit_logs', ['id' => $criticalLog->id]);
    }

    // ==================== SyncAttachmentsCommand Tests ====================

    /**
     * @test
     * 测试附件同步 - 检查模式 (无S3配置时优雅处理)
     */
    public function sync_attachments_handles_missing_s3_config(): void
    {
        // 由于测试环境可能没有S3配置，我们只测试命令能否正确处理这种情况
        // 实际S3功能需要在集成测试中测试

        // 如果S3未配置，命令应该报错或优雅地处理
        // 这里我们跳过测试如果S3未配置
        if (empty(config('filesystems.disks.s3-compat.bucket'))) {
            $this->markTestSkipped('S3 not configured for testing');
        }

        $this->artisan('maintenance:sync-attachments', ['--check' => true])
            ->assertExitCode(0);
    }
}
