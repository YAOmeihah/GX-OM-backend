<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceShareToken;
use App\Models\InvoiceShareTokenLog;
use App\Models\Payment;
use App\Models\RuntimeConfig;
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
}
