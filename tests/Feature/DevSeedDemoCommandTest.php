<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShareToken;
use App\Models\InvoiceShareTokenLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\RuntimeConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DevSeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_non_local_environment_without_force(): void
    {
        config(['app.env' => 'production']);

        $this->artisan('dev:seed-demo')
            ->expectsOutputToContain('Refusing to seed demo data outside local/testing')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Store::where('code', 'like', 'DEMO-%')->count());
    }

    public function test_clean_option_succeeds_on_empty_database(): void
    {
        $this->artisan('dev:seed-demo', ['--clean' => true])
            ->expectsOutputToContain('Demo data cleaned')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_clean_removes_only_demo_rows_and_preserves_manual_rows(): void
    {
        $manualStore = Store::factory()->create(['code' => 'REAL-A']);
        $manualUser = User::factory()->create([
            'name' => 'Manual User',
            'username' => 'manual_user',
            'email' => 'manual@example.com',
            'password' => Hash::make('password'),
        ]);
        $manualCustomer = Customer::factory()->create([
            'store_id' => $manualStore->id,
            'remarks' => 'manual record',
        ]);
        $manualInvoice = Invoice::factory()->unpaid()->create([
            'invoice_number' => 'REAL-INV-001',
            'store_id' => $manualStore->id,
            'customer_id' => $manualCustomer->id,
            'created_by' => $manualUser->id,
        ]);

        RuntimeConfig::create([
            'key' => 's3-compat',
            'value' => ['access_key' => 'real-key', 'secret_key' => 'real-secret'],
        ]);

        $this->createMinimalDemoRows();

        $this->artisan('dev:seed-demo', ['--clean' => true])
            ->expectsOutputToContain('Demo data cleaned')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('stores', ['id' => $manualStore->id, 'code' => 'REAL-A']);
        $this->assertDatabaseHas('users', ['id' => $manualUser->id, 'email' => 'manual@example.com']);
        $this->assertDatabaseHas('customers', ['id' => $manualCustomer->id]);
        $this->assertDatabaseHas('invoices', ['id' => $manualInvoice->id, 'invoice_number' => 'REAL-INV-001']);
        $this->assertDatabaseHas('runtime_configs', ['key' => 's3-compat']);

        $this->assertSame(0, Store::where('code', 'like', 'DEMO-%')->count());
        $this->assertSame(0, User::where('email', 'like', 'demo.%@example.com')->count());
        $this->assertSame(0, Customer::where('remarks', 'like', 'DEMO:%')->count());
        $this->assertSame(0, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->count());
        $this->assertSame(0, Payment::where('payment_number', 'like', 'DEMO-PAY-%')->count());
        $this->assertSame(0, Attachment::where('file_path', 'like', 'demo/%')->count());
        $this->assertSame(0, AttachmentUploadIntent::where('file_path', 'like', 'demo/%')->count());
        $this->assertSame(0, InvoiceShareToken::where('token', 'like', 'demo-%')->count());
        $this->assertSame(0, RuntimeConfig::where('key', 'like', 'demo.%')->count());
    }

    public function test_seed_creates_demo_stores_users_customers_and_roles(): void
    {
        $this->artisan('dev:seed-demo')
            ->expectsOutputToContain('Demo data seeded')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, Store::where('code', 'like', 'DEMO-%')->count());
        $this->assertSame(6, User::where('email', 'like', 'demo.%@example.com')->count());
        $this->assertSame(15, Customer::where('remarks', 'like', 'DEMO:%')->count());

        $admin = User::where('email', 'demo.admin@example.com')->firstOrFail();
        $ownerA = User::where('email', 'demo.owner.a@example.com')->firstOrFail();
        $staffA = User::where('email', 'demo.staff.a@example.com')->firstOrFail();
        $multi = User::where('email', 'demo.multi@example.com')->firstOrFail();

        $storeA = Store::where('code', 'DEMO-A')->firstOrFail();
        $storeB = Store::where('code', 'DEMO-B')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($ownerA->hasRole('store_owner'));
        $this->assertTrue($staffA->hasRole('store_staff'));
        $this->assertTrue($ownerA->belongsToStore($storeA->id));
        $this->assertTrue($multi->belongsToStore($storeA->id));
        $this->assertTrue($multi->belongsToStore($storeB->id));

        $this->assertDatabaseHas('roles', ['slug' => 'admin']);
        $this->assertDatabaseHas('roles', ['slug' => 'store_owner']);
        $this->assertDatabaseHas('roles', ['slug' => 'store_staff']);
        $this->assertGreaterThan(0, Role::where('slug', 'admin')->firstOrFail()->permissions()->count());
    }

    public function test_seed_creates_financial_scenarios(): void
    {
        $this->artisan('dev:seed-demo')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(45, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->count());
        $this->assertGreaterThanOrEqual(90, InvoiceItem::where('item_description', 'like', 'DEMO:%')->count());
        $this->assertBetween(25, 30, Payment::where('payment_number', 'like', 'DEMO-PAY-%')->count());
        $this->assertBetween(30, 40, PaymentAllocation::query()
            ->whereIn('allocated_by', User::where('email', 'like', 'demo.%@example.com')->pluck('id'))
            ->count());
        $this->assertSame(12, PaymentDiscount::where('reason', 'like', 'DEMO:%')->count());

        $this->assertGreaterThan(0, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->where('status', 'unpaid')->count());
        $this->assertGreaterThan(0, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->where('status', 'partially_paid')->count());
        $this->assertGreaterThan(0, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->where('status', 'paid')->count());
        $this->assertGreaterThan(0, Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->where('status', 'overdue')->count());

        foreach (['cash', 'bank_transfer', 'wechat', 'alipay', 'other'] as $method) {
            $this->assertDatabaseHas('payments', ['payment_method' => $method]);
        }

        foreach (['write_off', 'discount', 'promotion'] as $type) {
            $this->assertDatabaseHas('payment_discounts', ['discount_type' => $type]);
        }
    }

    private function createMinimalDemoRows(): void
    {
        $demoStore = Store::factory()->create(['code' => 'DEMO-A']);
        $demoUser = User::factory()->create([
            'name' => 'DEMO Admin',
            'username' => 'demo_admin',
            'email' => 'demo.admin@example.com',
            'password' => Hash::make('password'),
        ]);
        $demoCustomer = Customer::factory()->create([
            'store_id' => $demoStore->id,
            'remarks' => 'DEMO: cleanup fixture',
        ]);
        $demoInvoice = Invoice::factory()->unpaid()->create([
            'invoice_number' => 'DEMO-INV-CLEAN-001',
            'store_id' => $demoStore->id,
            'customer_id' => $demoCustomer->id,
            'created_by' => $demoUser->id,
        ]);
        $demoPayment = Payment::factory()->create([
            'payment_number' => 'DEMO-PAY-CLEAN-001',
            'store_id' => $demoStore->id,
            'customer_id' => $demoCustomer->id,
            'received_by' => $demoUser->id,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $demoInvoice->id,
            'line_uid' => (string) \Illuminate\Support\Str::uuid(),
            'item_name' => 'DEMO Item',
            'item_description' => 'DEMO: cleanup fixture',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Attachment::create([
            'attachable_type' => Invoice::class,
            'attachable_id' => $demoInvoice->id,
            'original_filename' => 'DEMO-clean.pdf',
            'stored_filename' => 'DEMO-clean.pdf',
            'file_path' => 'demo/cleanup/DEMO-clean.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $demoUser->id,
        ]);

        AttachmentUploadIntent::create([
            'attachable_type' => Invoice::class,
            'attachable_id' => $demoInvoice->id,
            'file_path' => 'demo/cleanup/DEMO-intent.pdf',
            'original_filename' => 'DEMO-intent.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $demoUser->id,
            'expires_at' => now()->addHour(),
        ]);

        $demoToken = InvoiceShareToken::create([
            'token' => 'demo-clean-token',
            'invoice_ids' => [$demoInvoice->id],
            'customer_id' => $demoCustomer->id,
            'store_id' => $demoStore->id,
            'created_by' => $demoUser->id,
            'expires_at' => now()->addDay(),
            'type' => 'fixed',
        ]);

        InvoiceShareTokenLog::create([
            'token_id' => $demoToken->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'demo-cleanup-fixture',
            'accessed_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $demoPayment->id,
            'invoice_id' => $demoInvoice->id,
            'amount' => 10,
            'allocated_by' => $demoUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_discounts')->insert([
            'payment_id' => $demoPayment->id,
            'invoice_id' => $demoInvoice->id,
            'discount_amount' => 5,
            'discount_type' => 'discount',
            'reason' => 'DEMO: cleanup fixture',
            'approved_by' => $demoUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RuntimeConfig::create([
            'key' => 'demo.s3-compat',
            'value' => ['demo_seeded' => true, 'access_key' => 'demo-key', 'secret_key' => 'demo-secret'],
        ]);
    }

    private function assertBetween(int $min, int $max, int $actual): void
    {
        $this->assertGreaterThanOrEqual($min, $actual);
        $this->assertLessThanOrEqual($max, $actual);
    }

    private function demoCounts(): array
    {
        return [
            'stores' => Store::where('code', 'like', 'DEMO-%')->count(),
            'users' => User::where('email', 'like', 'demo.%@example.com')->count(),
            'customers' => Customer::where('remarks', 'like', 'DEMO:%')->count(),
            'invoices' => Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->count(),
            'invoice_items' => InvoiceItem::where('item_description', 'like', 'DEMO:%')->count(),
            'payments' => Payment::where('payment_number', 'like', 'DEMO-PAY-%')->count(),
            'allocations' => PaymentAllocation::query()->count(),
            'discounts' => PaymentDiscount::where('reason', 'like', 'DEMO:%')->count(),
            'share_tokens' => InvoiceShareToken::where('token', 'like', 'demo-%')->count(),
            'attachments' => Attachment::where('file_path', 'like', 'demo/%')->count(),
            'upload_intents' => AttachmentUploadIntent::where('file_path', 'like', 'demo/%')->count(),
            'audit_logs' => AuditLog::where('description', 'like', 'DEMO:%')->count(),
            'runtime_configs' => RuntimeConfig::where('key', 'like', 'demo.%')->count(),
        ];
    }
}
