# Local Demo Seed Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a repeatable `php artisan dev:seed-demo` command that safely cleans and rebuilds complete local demo data without touching non-demo records.

**Architecture:** Keep the Artisan command thin and delegate all data lifecycle work to `Database\Seeders\DevDemoSeeder`. Every demo row uses a deterministic marker such as `DEMO-`, `demo.%@example.com`, `demo/%`, or `DEMO:` so cleanup is safe and idempotent. The generated graph covers users, stores, customers, invoices, items, payments, allocations, discounts, share tokens, attachments, audit logs, maintenance anomalies, and customer-store stats.

**Tech Stack:** Laravel 11, PHP 8.2, Artisan commands, Eloquent, PHPUnit 11, SQLite in-memory tests, MySQL local runtime.

---

## File Structure

- Create: `tests/Feature/DevSeedDemoCommandTest.php`
- Create: `app/Console/Commands/DevSeedDemoCommand.php`
- Create: `database/seeders/DevDemoSeeder.php`

No change to `database/seeders/DatabaseSeeder.php`: demo data stays out of normal seed flow.

## Execution Rules

- Use Serena for PHP symbol discovery and edits where practical.
- Follow TDD: write one failing behavior test, run it, implement the smallest code to pass, run it again.
- Do not use raw SQL dumps.
- Use `Schema::disableForeignKeyConstraints()` for MySQL-compatible constraint work, and the existing SQLite pattern from `tests/Feature/MaintenanceCommandsTest.php` when creating orphan maintenance records.
- Commit after each task.
- Keep any final Pint-only changes in a separate commit.

## Demo Count Targets

- Stores: exactly 3 rows with `stores.code LIKE 'DEMO-%'`.
- Users: exactly 6 rows with `users.email LIKE 'demo.%@example.com'`.
- Customers: exactly 15 rows with `customers.remarks LIKE 'DEMO:%'`.
- Invoices: exactly 45 rows with `invoices.invoice_number LIKE 'DEMO-INV-%'`.
- Invoice items: at least 90 rows with `invoice_items.item_description LIKE 'DEMO:%'`.
- Payments: between 25 and 30 rows with `payments.payment_number LIKE 'DEMO-PAY-%'`.
- Payment allocations: between 30 and 40 rows linked to demo payments, demo invoices, or demo users.
- Payment discounts: exactly 12 rows with `payment_discounts.reason LIKE 'DEMO:%'`.
- Share tokens: exactly 6 rows with `invoice_share_tokens.token LIKE 'demo-%'`.
- Attachments plus upload intents: exactly 12 rows under `demo/`.
- Manual audit logs: exactly 40 rows with `audit_logs.description LIKE 'DEMO:%'`.
- Maintenance anomalies: at least one demo row for orphan item, orphan allocation, amount mismatch, status mismatch, expired token, and old audit log.

---

### Task 1: Command Contract And Environment Guard

**Files:**
- Create: `tests/Feature/DevSeedDemoCommandTest.php`
- Create: `app/Console/Commands/DevSeedDemoCommand.php`
- Create: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Write the failing command contract tests**

Create `tests/Feature/DevSeedDemoCommandTest.php` with the first two tests:

```php
<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

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
}
```

- [ ] **Step 2: Run the new tests and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --compact
```

Expected: failure because the `dev:seed-demo` Artisan command is not defined.

- [ ] **Step 3: Implement the command shell**

Create `app/Console/Commands/DevSeedDemoCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Database\Seeders\DevDemoSeeder;
use Illuminate\Console\Command;

class DevSeedDemoCommand extends Command
{
    protected $signature = 'dev:seed-demo
        {--clean : Remove demo data only}
        {--force : Allow running outside local/testing}';

    protected $description = 'Clean and rebuild local demo data for backend development';

    public function handle(DevDemoSeeder $seeder): int
    {
        $env = config('app.env');
        if (! in_array($env, ['local', 'testing'], true) && ! $this->option('force')) {
            $this->error('Refusing to seed demo data outside local/testing. Use --force to override.');

            return self::FAILURE;
        }

        if ($this->option('clean')) {
            $summary = $seeder->cleanDemoData();
            $this->info('Demo data cleaned');
        } else {
            $seeder->cleanDemoData();
            $summary = $seeder->seedDemoData();
            $this->info('Demo data seeded');
        }

        foreach ($summary as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Implement the minimal seeder shell**

Create `database/seeders/DevDemoSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevDemoSeeder extends Seeder
{
    public function cleanDemoData(): array
    {
        return ['cleaned' => 0];
    }

    public function seedDemoData(): array
    {
        return ['seeded' => 0];
    }
}
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --compact
```

Expected: both tests pass.

- [ ] **Step 6: Verify command discovery**

Run:

```bash
php artisan list | rg "dev:seed-demo"
```

Expected: output contains `dev:seed-demo`.

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php app/Console/Commands/DevSeedDemoCommand.php database/seeders/DevDemoSeeder.php
git commit -m "feat: add demo seed command shell"
```

---

### Task 2: Safe Demo Cleanup

**Files:**
- Modify: `tests/Feature/DevSeedDemoCommandTest.php`
- Modify: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Add the failing cleanup safety test**

Append this test and helper methods to `tests/Feature/DevSeedDemoCommandTest.php`:

```php
use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShareToken;
use App\Models\Payment;
use App\Models\RuntimeConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
}

private function createMinimalDemoRows(): void
{
    $store = Store::factory()->create(['code' => 'DEMO-A']);
    $user = User::factory()->create([
        'name' => 'DEMO Admin',
        'username' => 'demo_admin',
        'email' => 'demo.admin@example.com',
        'password' => Hash::make('password'),
    ]);
    $customer = Customer::factory()->create([
        'store_id' => $store->id,
        'remarks' => 'DEMO: cleanup fixture',
    ]);
    $invoice = Invoice::factory()->unpaid()->create([
        'invoice_number' => 'DEMO-INV-CLEAN-001',
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'item_description' => 'DEMO: cleanup fixture',
        'quantity' => 1,
        'unit_price' => 100,
        'subtotal' => 100,
        'sort_order' => 1,
    ]);
    $payment = Payment::factory()->create([
        'payment_number' => 'DEMO-PAY-CLEAN-001',
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'received_by' => $user->id,
    ]);
    Attachment::create([
        'attachable_type' => Invoice::class,
        'attachable_id' => $invoice->id,
        'original_filename' => 'DEMO-clean.pdf',
        'stored_filename' => 'DEMO-clean.pdf',
        'file_path' => 'demo/cleanup/DEMO-clean.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'uploaded_by' => $user->id,
    ]);
    AttachmentUploadIntent::create([
        'attachable_type' => Invoice::class,
        'attachable_id' => $invoice->id,
        'file_path' => 'demo/cleanup/DEMO-intent.pdf',
        'original_filename' => 'DEMO-intent.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'uploaded_by' => $user->id,
        'expires_at' => now()->addHour(),
    ]);
    InvoiceShareToken::create([
        'token' => 'demo-clean-token',
        'invoice_ids' => [$invoice->id],
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_by' => $user->id,
        'expires_at' => now()->addDay(),
        'type' => 'fixed',
    ]);

    DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'amount' => 10,
        'allocated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

- [ ] **Step 2: Run the cleanup test and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=clean_removes_only_demo_rows --compact
```

Expected: failure because `cleanDemoData()` does not delete demo rows yet.

- [ ] **Step 3: Implement cleanup markers and dependency order**

In `database/seeders/DevDemoSeeder.php`, add imports and implement cleanup:

```php
use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerStoreStat;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShareToken;
use App\Models\InvoiceShareTokenLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\RuntimeConfig;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

public function cleanDemoData(): array
{
    $demoUserIds = User::where('email', 'like', 'demo.%@example.com')->pluck('id');
    $demoStoreIds = Store::where('code', 'like', 'DEMO-%')->pluck('id');
    $demoCustomerIds = Customer::where('remarks', 'like', 'DEMO:%')->pluck('id');
    $demoInvoiceIds = Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->pluck('id');
    $demoPaymentIds = Payment::where('payment_number', 'like', 'DEMO-PAY-%')->pluck('id');

    $summary = [];

    $summary['attachments'] = Attachment::where('file_path', 'like', 'demo/%')->delete();
    $summary['attachment_upload_intents'] = AttachmentUploadIntent::where('file_path', 'like', 'demo/%')->delete();
    $summary['invoice_share_token_logs'] = InvoiceShareTokenLog::whereIn(
        'token_id',
        InvoiceShareToken::where('token', 'like', 'demo-%')->pluck('id')
    )->delete();
    $summary['invoice_share_tokens'] = InvoiceShareToken::where('token', 'like', 'demo-%')->delete();
    $summary['payment_discounts'] = PaymentDiscount::where('reason', 'like', 'DEMO:%')
        ->orWhereIn('payment_id', $demoPaymentIds)
        ->orWhereIn('invoice_id', $demoInvoiceIds)
        ->delete();
    $summary['payment_allocations'] = PaymentAllocation::whereIn('payment_id', $demoPaymentIds)
        ->orWhereIn('invoice_id', $demoInvoiceIds)
        ->orWhereIn('allocated_by', $demoUserIds)
        ->delete();
    $summary['invoice_items'] = InvoiceItem::where('item_description', 'like', 'DEMO:%')
        ->orWhereIn('invoice_id', $demoInvoiceIds)
        ->delete();
    $summary['payments'] = Payment::whereIn('id', $demoPaymentIds)->delete();
    $summary['invoices'] = Invoice::whereIn('id', $demoInvoiceIds)->delete();
    $summary['customer_store_stats'] = CustomerStoreStat::whereIn('customer_id', $demoCustomerIds)
        ->orWhereIn('store_id', $demoStoreIds)
        ->delete();
    $summary['customers'] = Customer::whereIn('id', $demoCustomerIds)->delete();
    DB::table('store_user')->whereIn('user_id', $demoUserIds)->orWhereIn('store_id', $demoStoreIds)->delete();
    DB::table('role_user')->whereIn('user_id', $demoUserIds)->delete();
    $summary['users'] = User::whereIn('id', $demoUserIds)->delete();
    $summary['stores'] = Store::whereIn('id', $demoStoreIds)->delete();
    $summary['audit_logs'] = AuditLog::where('description', 'like', 'DEMO:%')
        ->orWhereIn('user_id', $demoUserIds)
        ->orWhereIn('business_store_id', $demoStoreIds)
        ->orWhereIn('actor_store_id', $demoStoreIds)
        ->delete();

    RuntimeConfig::where('key', 's3-compat')
        ->get()
        ->filter(fn (RuntimeConfig $config) => ($config->value['demo_seeded'] ?? false) === true)
        ->each->delete();

    return $summary;
}
```

- [ ] **Step 4: Run the cleanup test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=clean_removes_only_demo_rows --compact
```

Expected: the cleanup test passes.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php database/seeders/DevDemoSeeder.php
git commit -m "feat: clean demo seed data safely"
```

---

### Task 3: Base Demo Stores, Users, Customers, Roles

**Files:**
- Modify: `tests/Feature/DevSeedDemoCommandTest.php`
- Modify: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Add the failing base data test**

Append this test and count helper:

```php
use App\Models\Role;

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

private function demoCounts(): array
{
    return [
        'stores' => Store::where('code', 'like', 'DEMO-%')->count(),
        'users' => User::where('email', 'like', 'demo.%@example.com')->count(),
        'customers' => Customer::where('remarks', 'like', 'DEMO:%')->count(),
        'invoices' => Invoice::where('invoice_number', 'like', 'DEMO-INV-%')->count(),
        'invoice_items' => InvoiceItem::where('item_description', 'like', 'DEMO:%')->count(),
        'payments' => Payment::where('payment_number', 'like', 'DEMO-PAY-%')->count(),
        'discounts' => \App\Models\PaymentDiscount::where('reason', 'like', 'DEMO:%')->count(),
        'share_tokens' => InvoiceShareToken::where('token', 'like', 'demo-%')->count(),
        'attachments' => Attachment::where('file_path', 'like', 'demo/%')->count(),
        'upload_intents' => AttachmentUploadIntent::where('file_path', 'like', 'demo/%')->count(),
        'audit_logs' => \App\Models\AuditLog::where('description', 'like', 'DEMO:%')->count(),
    ];
}
```

- [ ] **Step 2: Run the base data test and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=seed_creates_demo_stores_users_customers --compact
```

Expected: failure because `seedDemoData()` still creates no rows.

- [ ] **Step 3: Implement baseline roles, stores, users, and customers**

In `DevDemoSeeder`, add imports for `DatabaseSeeder`, `Hash`, and `CustomerStatsService`, then implement:

```php
use App\Models\Role;
use App\Services\CustomerStatsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

public function seedDemoData(): array
{
    $this->call(DatabaseSeeder::class);

    return $this->withoutDemoAuditing(function () {
        $stores = $this->seedStores();
        $users = $this->seedUsers($stores);
        $customers = $this->seedCustomers($stores);

        return [
            'stores' => count($stores),
            'users' => count($users),
            'customers' => count($customers),
        ];
    });
}

private function withoutDemoAuditing(callable $callback): mixed
{
    return User::withoutAuditingDo(fn () =>
        Store::withoutAuditingDo(fn () =>
            Customer::withoutAuditingDo(fn () =>
                Invoice::withoutAuditingDo(fn () =>
                    Payment::withoutAuditingDo(fn () =>
                        Attachment::withoutAuditingDo($callback)
                    )
                )
            )
        )
    );
}

private function seedStores(): array
{
    return [
        'A' => Store::create([
            'name' => 'DEMO-门店A 主流程',
            'code' => 'DEMO-A',
            'address' => 'DEMO: 本地测试地址 A',
            'phone' => '13800000001',
            'description' => 'DEMO: 主流程门店',
            'is_active' => true,
            'wechat_pay_code_data' => 'DEMO-WECHAT-A',
            'alipay_code_data' => 'DEMO-ALIPAY-A',
        ]),
        'B' => Store::create([
            'name' => 'DEMO-门店B 权限隔离',
            'code' => 'DEMO-B',
            'address' => 'DEMO: 本地测试地址 B',
            'phone' => '13800000002',
            'description' => 'DEMO: 权限隔离门店',
            'is_active' => true,
            'wechat_pay_code_data' => 'DEMO-WECHAT-B',
            'alipay_code_data' => 'DEMO-ALIPAY-B',
        ]),
        'C' => Store::create([
            'name' => 'DEMO-门店C 边界场景',
            'code' => 'DEMO-C',
            'address' => 'DEMO: 本地测试地址 C',
            'phone' => '13800000003',
            'description' => 'DEMO: 边界门店',
            'is_active' => true,
            'wechat_pay_code_data' => 'DEMO-WECHAT-C',
            'alipay_code_data' => 'DEMO-ALIPAY-C',
        ]),
    ];
}

private function seedUsers(array $stores): array
{
    $password = Hash::make('password');
    $roles = Role::whereIn('slug', ['admin', 'store_owner', 'store_staff'])->get()->keyBy('slug');

    $users = [
        'admin' => User::create(['name' => 'DEMO 系统管理员', 'username' => 'demo_admin', 'email' => 'demo.admin@example.com', 'password' => $password]),
        'ownerA' => User::create(['name' => 'DEMO 门店A店长', 'username' => 'demo_owner_a', 'email' => 'demo.owner.a@example.com', 'password' => $password]),
        'ownerB' => User::create(['name' => 'DEMO 门店B店长', 'username' => 'demo_owner_b', 'email' => 'demo.owner.b@example.com', 'password' => $password]),
        'staffA' => User::create(['name' => 'DEMO 门店A店员', 'username' => 'demo_staff_a', 'email' => 'demo.staff.a@example.com', 'password' => $password]),
        'staffB' => User::create(['name' => 'DEMO 门店B店员', 'username' => 'demo_staff_b', 'email' => 'demo.staff.b@example.com', 'password' => $password]),
        'multi' => User::create(['name' => 'DEMO 跨店用户', 'username' => 'demo_multi', 'email' => 'demo.multi@example.com', 'password' => $password]),
    ];

    $users['admin']->roles()->sync([$roles['admin']->id]);
    $users['ownerA']->roles()->sync([$roles['store_owner']->id]);
    $users['ownerB']->roles()->sync([$roles['store_owner']->id]);
    $users['staffA']->roles()->sync([$roles['store_staff']->id]);
    $users['staffB']->roles()->sync([$roles['store_staff']->id]);
    $users['multi']->roles()->sync([$roles['store_owner']->id, $roles['store_staff']->id]);

    $users['ownerA']->stores()->sync([$stores['A']->id]);
    $users['ownerB']->stores()->sync([$stores['B']->id]);
    $users['staffA']->stores()->sync([$stores['A']->id]);
    $users['staffB']->stores()->sync([$stores['B']->id]);
    $users['multi']->stores()->sync([$stores['A']->id, $stores['B']->id]);

    return $users;
}
```

Add `seedCustomers()` with 15 deterministic rows:

```php
private function seedCustomers(array $stores): array
{
    $definitions = [
        ['key' => 'debtA', 'store' => 'A', 'name' => 'DEMO 张三 欠款客户', 'phone' => '13900000001', 'email' => 'demo.customer.01@example.com', 'remarks' => 'DEMO: debt customer store A'],
        ['key' => 'paidA', 'store' => 'A', 'name' => 'DEMO 李四 无欠款客户', 'phone' => '13900000002', 'email' => 'demo.customer.02@example.com', 'remarks' => 'DEMO: paid customer store A'],
        ['key' => 'overdueA', 'store' => 'A', 'name' => 'DEMO 王五 逾期客户', 'phone' => '13900000003', 'email' => 'demo.customer.03@example.com', 'remarks' => 'DEMO: overdue customer store A'],
        ['key' => 'discountA', 'store' => 'A', 'name' => 'DEMO 赵六 折扣客户', 'phone' => '13900000004', 'email' => 'demo.customer.04@example.com', 'remarks' => 'DEMO: discount customer store A'],
        ['key' => 'writeoffA', 'store' => 'A', 'name' => 'DEMO 钱七 核销客户', 'phone' => '13900000005', 'email' => 'demo.customer.05@example.com', 'remarks' => 'DEMO: write off customer store A'],
        ['key' => 'attachmentA', 'store' => 'A', 'name' => 'DEMO 孙八 附件客户', 'phone' => '13900000006', 'email' => 'demo.customer.06@example.com', 'remarks' => 'DEMO: attachment customer store A'],
        ['key' => 'shareA', 'store' => 'A', 'name' => 'DEMO 周九 分享客户', 'phone' => '13900000007', 'email' => 'demo.customer.07@example.com', 'remarks' => 'DEMO: share token customer store A'],
        ['key' => 'sameNameA', 'store' => 'A', 'name' => 'DEMO 同名客户', 'phone' => '13900000008', 'email' => 'demo.customer.08@example.com', 'remarks' => 'DEMO: same name customer store A'],
        ['key' => 'sameNameB', 'store' => 'B', 'name' => 'DEMO 同名客户', 'phone' => '13900000009', 'email' => 'demo.customer.09@example.com', 'remarks' => 'DEMO: same name customer store B'],
        ['key' => 'debtB', 'store' => 'B', 'name' => 'DEMO 吴十 门店B欠款', 'phone' => '13900000010', 'email' => 'demo.customer.10@example.com', 'remarks' => 'DEMO: debt customer store B'],
        ['key' => 'paidB', 'store' => 'B', 'name' => 'DEMO 郑一 门店B无欠款', 'phone' => '13900000011', 'email' => 'demo.customer.11@example.com', 'remarks' => 'DEMO: paid customer store B'],
        ['key' => 'filterB', 'store' => 'B', 'name' => 'DEMO 筛选客户B', 'phone' => '13900000012', 'email' => 'demo.filter.b@example.com', 'remarks' => 'DEMO: filter customer store B'],
        ['key' => 'edgeC', 'store' => 'C', 'name' => 'DEMO 边界客户C', 'phone' => '13900000013', 'email' => 'demo.customer.13@example.com', 'remarks' => 'DEMO: edge customer store C'],
        ['key' => 'emptyC', 'store' => 'C', 'name' => 'DEMO 空态客户C', 'phone' => '13900000014', 'email' => 'demo.customer.14@example.com', 'remarks' => 'DEMO: empty state customer store C'],
        ['key' => 'maintenanceA', 'store' => 'A', 'name' => 'DEMO 维护场景客户', 'phone' => '13900000015', 'email' => 'demo.customer.15@example.com', 'remarks' => 'DEMO: maintenance customer store A'],
    ];

    $customers = [];
    foreach ($definitions as $definition) {
        $customers[$definition['key']] = Customer::create([
            'store_id' => $stores[$definition['store']]->id,
            'name' => $definition['name'],
            'phone' => $definition['phone'],
            'email' => $definition['email'],
            'address' => 'DEMO: 本地测试地址 '.$definition['key'],
            'id_card' => '110101199001'.str_pad((string) count($customers), 6, '0', STR_PAD_LEFT),
            'remarks' => $definition['remarks'],
        ]);
    }

    return $customers;
}
```

- [ ] **Step 4: Run the base data test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=seed_creates_demo_stores_users_customers --compact
```

Expected: the base data test passes.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php database/seeders/DevDemoSeeder.php
git commit -m "feat: seed demo stores users and customers"
```

---

### Task 4: Financial Demo Graph

**Files:**
- Modify: `tests/Feature/DevSeedDemoCommandTest.php`
- Modify: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Add the failing financial coverage test**

Append:

```php
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;

public function test_seed_creates_financial_scenarios(): void
{
    $this->artisan('dev:seed-demo')->assertExitCode(Command::SUCCESS);

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

private function assertBetween(int $min, int $max, int $actual): void
{
    $this->assertGreaterThanOrEqual($min, $actual);
    $this->assertLessThanOrEqual($max, $actual);
}
```

- [ ] **Step 2: Run the financial test and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=seed_creates_financial_scenarios --compact
```

Expected: failure because no invoice, payment, allocation, or discount rows exist.

- [ ] **Step 3: Implement financial seed methods**

Add these method boundaries to `DevDemoSeeder` and call them from `seedDemoData()` after customers:

```php
$invoices = $this->seedInvoicesAndItems($stores, $users, $customers);
$payments = $this->seedPaymentsAndAllocations($stores, $users, $customers, $invoices);
$discounts = $this->seedDiscounts($users, $payments, $invoices);
```

Implementation rules:

- `seedInvoicesAndItems()` creates exactly 45 invoices: 3 per customer.
- Every invoice number uses `DEMO-INV-YYYYMMDD-###`.
- Every invoice has 2 invoice items with `item_description` beginning with `DEMO:`.
- Status distribution includes `unpaid`, `partially_paid`, `paid`, and `overdue`.
- Due dates include past dates, today, current month, and future dates.
- `seedPaymentsAndAllocations()` creates 25 to 30 payments with `DEMO-PAY-YYYYMMDD-###`.
- Payment methods cover `cash`, `bank_transfer`, `wechat`, `alipay`, and `other`.
- Use `Payment::allocateToInvoice()` for normal allocations so `paid_amount`, `allocated_amount`, status, and stats stay consistent.
- Create 3 unallocated payments for allocation suggestion testing.
- Create several payments with more than one allocation for batch allocation and revoke flows.
- `seedDiscounts()` creates exactly 12 discounts through `Payment::createDiscount()` where the target invoice has enough actual remaining amount.
- Discount reasons begin with `DEMO:`.
- After each discount, call `$invoice->refresh()->updateStatus()` for status consistency.

- [ ] **Step 4: Run the financial test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=seed_creates_financial_scenarios --compact
```

Expected: the financial test passes.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php database/seeders/DevDemoSeeder.php
git commit -m "feat: seed demo financial scenarios"
```

---

### Task 5: Share, Attachment, Audit, Maintenance, Stats Data

**Files:**
- Modify: `tests/Feature/DevSeedDemoCommandTest.php`
- Modify: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Add the failing support-module coverage test**

Append:

```php
use App\Models\AuditLog;
use App\Models\CustomerStoreStat;
use App\Models\InvoiceShareTokenLog;

public function test_seed_creates_supporting_module_data_and_stats(): void
{
    $this->artisan('dev:seed-demo')->assertExitCode(Command::SUCCESS);

    $this->assertSame(6, InvoiceShareToken::where('token', 'like', 'demo-%')->count());
    $this->assertDatabaseHas('invoice_share_tokens', ['type' => 'fixed']);
    $this->assertDatabaseHas('invoice_share_tokens', ['type' => 'dynamic']);
    $this->assertGreaterThan(0, InvoiceShareToken::where('token', 'like', 'demo-expired-%')->where('expires_at', '<', now())->count());
    $this->assertGreaterThan(0, InvoiceShareTokenLog::count());

    $this->assertSame(8, Attachment::where('file_path', 'like', 'demo/%')->count());
    $this->assertSame(4, AttachmentUploadIntent::where('file_path', 'like', 'demo/%')->count());

    $this->assertSame(40, AuditLog::where('description', 'like', 'DEMO:%')->count());

    $demoCustomerIds = Customer::where('remarks', 'like', 'DEMO:%')->pluck('id');
    $demoStoreIds = Store::where('code', 'like', 'DEMO-%')->pluck('id');
    $this->assertGreaterThan(0, CustomerStoreStat::whereIn('customer_id', $demoCustomerIds)->whereIn('store_id', $demoStoreIds)->count());

    $this->assertGreaterThan(0, InvoiceItem::where('item_description', 'like', 'DEMO: orphan%')->count());
    $this->assertGreaterThan(0, PaymentAllocation::whereIn('allocated_by', User::where('email', 'like', 'demo.%@example.com')->pluck('id'))
        ->whereNotIn('payment_id', Payment::pluck('id'))
        ->count());
    $this->assertGreaterThan(0, Invoice::where('description', 'like', 'DEMO: maintenance amount mismatch%')->count());
    $this->assertGreaterThan(0, Invoice::where('description', 'like', 'DEMO: maintenance status mismatch%')->count());
}
```

- [ ] **Step 2: Run the support-module test and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=supporting_module_data_and_stats --compact
```

Expected: failure because share, attachment, audit, maintenance, and stats data are missing.

- [ ] **Step 3: Implement support-module seed methods**

Add these calls after the financial methods:

```php
$this->seedShareTokens($users, $stores, $customers, $invoices);
$this->seedAttachments($users, $invoices, $payments);
$this->seedRuntimeConfigIfMissing();
$this->seedAuditLogs($users, $stores, $customers, $invoices, $payments);
$this->seedMaintenanceScenarios($users, $customers, $stores, $invoices, $payments);
$this->syncStats($customers, $stores);
```

Implementation rules:

- Share tokens:
  - `demo-fixed-single-token`
  - `demo-fixed-multiple-token`
  - `demo-dynamic-store-a-token`
  - `demo-dynamic-store-b-token`
  - `demo-expired-fixed-token`
  - `demo-access-log-token`
  - Add at least one `InvoiceShareTokenLog` for `demo-access-log-token`.
- Attachments:
  - Create 8 `Attachment` rows under `demo/attachments/`.
  - Create 4 `AttachmentUploadIntent` rows under `demo/intents/`.
  - Use invoice and payment attachables.
  - Use image, PDF, text, and Word MIME types.
- Runtime config:
  - If `RuntimeConfig::where('key', 's3-compat')->exists()` is false, create value with `demo_seeded => true`.
  - Do not overwrite an existing local S3 config.
- Audit logs:
  - Create 40 rows directly with `description` beginning with `DEMO:`.
  - Cover actions `create`, `update`, `view`, `allocate`, `discount`, `upload`, `export`, and `maintenance_execute`.
  - Use store scope fields where relevant.
- Maintenance:
  - Use a helper named `withRelaxedForeignKeys(callable $callback)` for orphan rows.
  - For SQLite, match the existing test pattern: `PRAGMA defer_foreign_keys = ON` before inserts and `OFF` afterward.
  - For other drivers, use `Schema::disableForeignKeyConstraints()` and `Schema::enableForeignKeyConstraints()`.
  - Create an orphan invoice item with `item_description = 'DEMO: orphan invoice item'`.
  - Create an orphan payment allocation with `allocated_by` set to the demo admin user.
  - Create one amount mismatch invoice by changing `invoices.amount` with `DB::table('invoices')->whereKey(...)->update(...)` after item creation.
  - Create one status mismatch invoice by changing `invoices.status` with `DB::table()` after allocations.
- Stats:
  - Loop through every demo customer and every demo store, calling `CustomerStatsService::syncCustomerStoreStats($customer->id, $store->id)`.

- [ ] **Step 4: Run the support-module test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=supporting_module_data_and_stats --compact
```

Expected: the support-module test passes.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php database/seeders/DevDemoSeeder.php
git commit -m "feat: seed demo support module data"
```

---

### Task 6: Idempotency, Final Verification, Formatting

**Files:**
- Modify: `tests/Feature/DevSeedDemoCommandTest.php`
- Modify: `app/Console/Commands/DevSeedDemoCommand.php`
- Modify: `database/seeders/DevDemoSeeder.php`

- [ ] **Step 1: Add the failing idempotency test**

Append:

```php
public function test_seed_demo_is_idempotent_and_clean_removes_demo_data(): void
{
    $this->artisan('dev:seed-demo')->assertExitCode(Command::SUCCESS);
    $firstCounts = $this->demoCounts();

    $this->artisan('dev:seed-demo')->assertExitCode(Command::SUCCESS);
    $secondCounts = $this->demoCounts();

    $this->assertSame($firstCounts, $secondCounts);
    $this->assertSame(3, $secondCounts['stores']);
    $this->assertSame(6, $secondCounts['users']);
    $this->assertSame(15, $secondCounts['customers']);
    $this->assertSame(45, $secondCounts['invoices']);
    $this->assertSame(12, $secondCounts['discounts']);
    $this->assertSame(6, $secondCounts['share_tokens']);
    $this->assertSame(8, $secondCounts['attachments']);
    $this->assertSame(4, $secondCounts['upload_intents']);
    $this->assertSame(40, $secondCounts['audit_logs']);

    $this->artisan('dev:seed-demo', ['--clean' => true])->assertExitCode(Command::SUCCESS);

    foreach ($this->demoCounts() as $count) {
        $this->assertSame(0, $count);
    }
}
```

- [ ] **Step 2: Run the idempotency test and verify RED**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --filter=idempotent --compact
```

Expected: failure if any demo category grows on repeated runs or survives `--clean`.

- [ ] **Step 3: Stabilize repeated runs**

Make the seeder deterministic:

- Call `cleanDemoData()` before `seedDemoData()` in command default path.
- Use fixed demo emails, usernames, store codes, invoice numbers, payment numbers, token strings, file paths, and audit descriptions.
- Ensure every normal financial write uses generated demo records from the current run.
- Ensure orphan rows use demo user IDs or demo marker text so cleanup can remove them.
- Ensure `cleanDemoData()` reads demo IDs before deleting parent rows.
- Ensure `cleanDemoData()` can run when some demo categories are already absent.

- [ ] **Step 4: Run all command tests and verify GREEN**

Run:

```bash
php artisan test tests/Feature/DevSeedDemoCommandTest.php --compact
```

Expected: every `DevSeedDemoCommandTest` test passes.

- [ ] **Step 5: Run full backend test suite**

Run:

```bash
php artisan test --compact
```

Expected: full suite exits 0. Existing PHPUnit 12 doc-comment metadata warnings may still appear.

- [ ] **Step 6: Run style check**

Run:

```bash
vendor/bin/pint --test
```

Expected: Pint exits 0. If it reports formatting differences, run `vendor/bin/pint`, then rerun `vendor/bin/pint --test`.

- [ ] **Step 7: Manual local command smoke test**

Run on the local `.env` MySQL database after confirming it is disposable for demo rows:

```bash
php artisan dev:seed-demo
php artisan dev:seed-demo
php artisan dev:seed-demo --clean
```

Expected:

- First run creates demo data and prints summary counts.
- Second run prints the same summary counts.
- `--clean` removes demo rows and leaves baseline roles, permissions, default admin, and non-demo data.

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/DevSeedDemoCommandTest.php app/Console/Commands/DevSeedDemoCommand.php database/seeders/DevDemoSeeder.php
git commit -m "test: verify demo seed idempotency"
```

---

## Final Review Checklist

- [ ] `php artisan dev:seed-demo` creates all target categories.
- [ ] `php artisan dev:seed-demo` can be repeated without increasing demo row counts.
- [ ] `php artisan dev:seed-demo --clean` removes demo rows only.
- [ ] Existing `DatabaseSeeder` remains unchanged.
- [ ] Existing runtime S3 config is not overwritten.
- [ ] Full test suite passes with exit 0.
- [ ] Pint check passes with exit 0.
