<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceShareToken;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\MaintenanceScanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Store $store;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin', 'name' => '系统管理员']);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->store = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Sanctum::actingAs($this->admin);
    }

    public function test_export_response_does_not_expose_server_absolute_path(): void
    {
        $scan = app(MaintenanceScanService::class)->scanHistoryCleanup([
            'months' => 3,
            'targets' => ['invoices', 'payments'],
        ]);

        $response = $this->postJson('/api/maintenance/export', [
            'scan_id' => $scan['scan_id'],
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.path')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'filename',
                    'download_url',
                    'size',
                ],
            ]);

        $filename = $response->json('data.filename');

        $this->assertMatchesRegularExpression('/^export_[a-z_]+_\d{8}_\d{6}\.json$/', $filename);
    }

    public function test_export_download_rejects_non_whitelisted_filename(): void
    {
        $exportDir = storage_path('app/maintenance_exports');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $unsafeFilenames = [
            'export-history-cleanup-20260101-000000.json',
            'not_an_export.txt',
        ];

        foreach ($unsafeFilenames as $filename) {
            file_put_contents("{$exportDir}/{$filename}", '{}');
        }

        $responses = array_map(
            fn (string $filename) => $this->getJson("/api/maintenance/export/{$filename}"),
            $unsafeFilenames
        );

        foreach ($responses as $response) {
            $response->assertNotFound();
        }
    }

    public function test_execute_cleanup_uses_type_and_id_selection_key(): void
    {
        $createdAt = Carbon::now()->subMonths(4);

        $invoice = Invoice::factory()->create([
            'id' => 1,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $payment = Payment::factory()->create([
            'id' => 1,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'received_by' => $this->admin->id,
            'amount' => 100,
            'allocated_amount' => 100,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'allocated_by' => $this->admin->id,
        ]);

        $scan = app(MaintenanceScanService::class)->scanHistoryCleanup([
            'months' => 3,
            'targets' => ['invoices', 'payments'],
        ]);

        $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
            'selected_keys' => ["invoice:{$invoice->id}"],
        ])->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_audit_cleanup_uses_selected_keys_from_cached_scan_items(): void
    {
        $expiredAt = Carbon::now()->subDays(100);

        $selectedLog = AuditLog::factory()->create([
            'action' => 'create',
            'created_at' => $expiredAt,
        ]);

        $unselectedLog = AuditLog::factory()->create([
            'action' => 'update',
            'created_at' => $expiredAt,
        ]);

        $scan = app(MaintenanceScanService::class)->scanAuditLogs([
            'normal_days' => 90,
            'critical_days' => 365,
            'page' => 1,
            'per_page' => 50,
        ]);

        $this->assertContains("audit_log:{$selectedLog->id}", collect($scan['items'])->pluck('selection_key')->all());

        $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
            'selected_keys' => ["audit_log:{$selectedLog->id}"],
        ])
            ->assertOk()
            ->assertJsonPath('data.deleted.audit_logs', 1);

        $this->assertDatabaseMissing('audit_logs', ['id' => $selectedLog->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $unselectedLog->id]);
    }

    public function test_integrity_allocation_selected_keys_distinguish_payment_and_invoice_mismatches(): void
    {
        $payment = Payment::factory()->create([
            'id' => 1,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'received_by' => $this->admin->id,
            'amount' => 100,
            'allocated_amount' => 100,
        ]);

        $invoice = Invoice::factory()->create([
            'id' => 1,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
        ]);

        $scan = app(MaintenanceScanService::class)->scanIntegrityIssues([
            'types' => ['payment_allocation'],
            'page' => 1,
            'per_page' => 50,
        ]);

        $selectionKeys = collect($scan['items'])->pluck('selection_key')->all();

        $this->assertContains("payment_allocation_mismatch_payment:{$payment->id}", $selectionKeys);
        $this->assertContains("payment_allocation_mismatch_invoice:{$invoice->id}", $selectionKeys);
        $this->assertCount(2, array_unique($selectionKeys));

        $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
            'selected_keys' => ["payment_allocation_mismatch_invoice:{$invoice->id}"],
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'allocated_amount' => 100,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
        ]);
    }

    public function test_integrity_payment_allocation_invoice_repair_marks_past_due_zero_settled_invoice_overdue(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'due_date' => Carbon::now()->subDay(),
        ]);

        $scan = app(MaintenanceScanService::class)->scanIntegrityIssues([
            'types' => ['payment_allocation'],
            'page' => 1,
            'per_page' => 50,
        ]);

        $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
            'selected_keys' => ["payment_allocation_mismatch_invoice:{$invoice->id}"],
        ])->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);
    }

    public function test_integrity_amount_repair_recalculates_status_and_customer_stats(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'line_uid' => (string) \Illuminate\Support\Str::uuid(),
            'item_name' => 'API repair item',
            'quantity' => 1,
            'unit_price' => 250,
            'subtotal' => 250,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Services\CustomerStatsService::class)->syncCustomerStoreStats($this->customer->id, $this->store->id);
        $this->assertDatabaseHas('customer_store_stats', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'total_debt' => 0,
        ]);

        $scan = app(MaintenanceScanService::class)->scanIntegrityIssues([
            'types' => ['invoice_amount'],
            'page' => 1,
            'per_page' => 50,
        ]);

        $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
            'selected_keys' => ["invoice_amount_mismatch:{$invoice->id}"],
        ])->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'amount' => 250,
            'paid_amount' => 100,
            'status' => 'partially_paid',
        ]);
        $this->assertDatabaseHas('customer_store_stats', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'total_debt' => 150,
        ]);
    }

    public function test_expired_token_scan_caches_all_items_for_execute_all(): void
    {
        $invoice = Invoice::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
        ]);

        foreach (range(1, 3) as $index) {
            InvoiceShareToken::create([
                'token' => InvoiceShareToken::generateToken(),
                'invoice_ids' => [$invoice->id],
                'customer_id' => $this->customer->id,
                'store_id' => $this->store->id,
                'created_by' => $this->admin->id,
                'expires_at' => Carbon::now()->subDays(10 + $index),
                'type' => InvoiceShareToken::TYPE_FIXED,
            ]);
        }

        $scan = app(MaintenanceScanService::class)->scanExpiredTokens([
            'days' => 7,
            'page' => 1,
            'per_page' => 1,
        ]);

        $this->assertSame(3, $scan['total_items']);
        $this->assertCount(1, $scan['items']);

        $response = $this->postJson('/api/maintenance/execute', [
            'scan_id' => $scan['scan_id'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.deleted.share_tokens', 3);

        $this->assertDatabaseCount('invoice_share_tokens', 0);
    }
}
