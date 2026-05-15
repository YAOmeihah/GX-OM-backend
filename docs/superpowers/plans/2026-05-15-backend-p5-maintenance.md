# Backend P5 Maintenance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the P5 backend maintenance batch with isolated, testable engineering changes and a final separated Pint formatting commit.

**Architecture:** Use small services around permission gate registration, discount permission checks, and payment creation so controllers stay at the HTTP boundary. Establish CI/static analysis and remove the abandoned Composer dependency path without changing core business behavior.

**Tech Stack:** Laravel 11, PHP 8.2, PHPUnit 11, Laravel Pint, Composer 2.8, Larastan/PHPStan, GitHub Actions.

---

## File Structure

- Create: `app/Services/PermissionGateRegistrar.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `app/Models/Permission.php`
- Create: `tests/Feature/AuthServiceProviderTest.php`
- Create: `app/Services/DiscountPermissionService.php`
- Modify: `app/Http/Middleware/CheckDiscountPermission.php`
- Modify: `app/Services/PaymentDiscountService.php`
- Create: `tests/Unit/DiscountPermissionServiceTest.php`
- Create: `app/Services/PaymentCreationService.php`
- Modify: `app/Http/Controllers/PaymentController.php`
- Modify: `tests/Feature/PaymentDiscountApiTest.php`
- Modify: `database/migrations/2026_03_16_000001_add_line_uid_to_invoice_items_table.php`
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `phpstan.neon`
- Create: `.github/workflows/backend-ci.yml`
- Delete: `config/scribe.php`
- Delete: `update-docs.bat`
- Delete: `update-api-docs.bat`
- Delete: `generate-markdown-docs.php`
- Format-only: files touched by `vendor/bin/pint`

## Execution Rules

- Use the worktree at `D:\Hrlni\Desktop\GX-OM\GX-OM-backend\.worktrees\p5-backend-maintenance`.
- Use Serena for PHP code discovery and edits where practical.
- Do not edit files outside the task write set.
- You are not alone in the codebase. Do not revert edits made by other agents; adjust to the current file state.
- Follow TDD for PHP behavior changes: write the failing test, run it and confirm the expected failure, implement, then run the passing verification.
- Commit after each task. Keep the final Pint run in its own commit.

---

### Task 1: Safe Permission Gate Registration

**Files:**
- Create: `app/Services/PermissionGateRegistrar.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `app/Models/Permission.php`
- Test: `tests/Feature/AuthServiceProviderTest.php`

- [ ] **Step 1: Write the failing provider test**

Create `tests/Feature/AuthServiceProviderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Providers\AuthServiceProvider;
use App\Services\PermissionGateRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_service_provider_boots_when_permissions_table_is_missing(): void
    {
        Schema::dropIfExists('permissions');

        $provider = new AuthServiceProvider($this->app);
        $provider->boot($this->app->make(PermissionGateRegistrar::class));

        $this->assertTrue(true);
    }

    public function test_permission_gate_registrar_caches_permission_slugs_and_forgets_cache_on_permission_change(): void
    {
        Cache::flush();

        Permission::create([
            'name' => 'View invoices',
            'slug' => 'invoices.view',
            'description' => 'View invoice list',
        ]);

        $registrar = $this->app->make(PermissionGateRegistrar::class);
        $registrar->register();

        $this->assertTrue(Gate::has('invoices.view'));
        $this->assertSame(['invoices.view'], Cache::get(PermissionGateRegistrar::CACHE_KEY));

        Permission::create([
            'name' => 'Create invoices',
            'slug' => 'invoices.create',
            'description' => 'Create invoice',
        ]);

        $this->assertNull(Cache::get(PermissionGateRegistrar::CACHE_KEY));
    }
}
```

- [ ] **Step 2: Run the provider test and confirm RED**

Run:

```powershell
php artisan test tests/Feature/AuthServiceProviderTest.php --compact
```

Expected: FAIL because `App\Services\PermissionGateRegistrar` does not exist.

- [ ] **Step 3: Create the gate registrar service**

Create `app/Services/PermissionGateRegistrar.php`:

```php
<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class PermissionGateRegistrar
{
    public const CACHE_KEY = 'authorization.permission_slugs';

    public function __construct(private readonly CacheRepository $cache) {}

    public function register(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isAdmin()) {
                return true;
            }

            return null;
        });

        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissionSlugs() as $slug) {
            Gate::define($slug, function (User $user) use ($slug) {
                return $user->hasPermission($slug);
            });
        }
    }

    public function forgetCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, string>
     */
    private function permissionSlugs(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, function () {
            return Permission::query()
                ->orderBy('id')
                ->pluck('slug')
                ->all();
        });
    }
}
```

- [ ] **Step 4: Delegate provider boot to the registrar**

Replace `AuthServiceProvider::boot()` with:

```php
public function boot(PermissionGateRegistrar $registrar): void
{
    $registrar->register();
}
```

Add the import:

```php
use App\Services\PermissionGateRegistrar;
```

- [ ] **Step 5: Flush permission slug cache on permission changes**

In `app/Models/Permission.php`, add:

```php
use App\Services\PermissionGateRegistrar;
```

Add this method inside the `Permission` class:

```php
protected static function booted(): void
{
    $forgetPermissionGateCache = function (): void {
        app(PermissionGateRegistrar::class)->forgetCache();
    };

    static::saved($forgetPermissionGateCache);
    static::deleted($forgetPermissionGateCache);
}
```

- [ ] **Step 6: Verify GREEN**

Run:

```powershell
php artisan test tests/Feature/AuthServiceProviderTest.php --compact
```

Expected: PASS.

- [ ] **Step 7: Commit Task 1**

```powershell
git add app/Services/PermissionGateRegistrar.php app/Providers/AuthServiceProvider.php app/Models/Permission.php tests/Feature/AuthServiceProviderTest.php
git commit -m "fix: guard permission gate registration"
```

---

### Task 2: Discount Permission Service Boundary

**Files:**
- Create: `app/Services/DiscountPermissionService.php`
- Modify: `app/Http/Middleware/CheckDiscountPermission.php`
- Modify: `app/Services/PaymentDiscountService.php`
- Test: `tests/Unit/DiscountPermissionServiceTest.php`

- [ ] **Step 1: Write the failing domain permission tests**

Create `tests/Unit/DiscountPermissionServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run the test and confirm RED**

Run:

```powershell
php artisan test tests/Unit/DiscountPermissionServiceTest.php --compact
```

Expected: FAIL because `App\Services\DiscountPermissionService` does not exist.

- [ ] **Step 3: Create the domain service**

Create `app/Services/DiscountPermissionService.php`:

```php
<?php

namespace App\Services;

use App\Models\User;

class DiscountPermissionService
{
    public function canApproveDiscount(User $user, int $storeId, ?string $discountType = null, ?float $amount = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->stores()->where('store_id', $storeId)->exists()) {
            return false;
        }

        if ($discountType !== null) {
            if (! $this->hasDiscountTypePermission($user, $discountType, $storeId)) {
                return false;
            }

            if ($amount !== null) {
                return $this->canApproveAmount($user, $discountType, $amount);
            }

            return true;
        }

        return $this->hasGeneralDiscountPermission($user, $storeId);
    }

    public function hasDiscountTypePermission(User $user, string $discountType, ?int $storeId = null): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        foreach ($discountConfig['approval_roles'] ?? [] as $role) {
            if (! $user->hasRole($role)) {
                continue;
            }

            if (in_array($role, ['store_owner', 'store_staff'], true) && $storeId !== null) {
                return $user->stores()->where('store_id', $storeId)->exists();
            }

            return true;
        }

        return false;
    }

    public function hasGeneralDiscountPermission(User $user, ?int $storeId = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('store_owner')) {
            return $storeId === null || $user->stores()->where('store_id', $storeId)->exists();
        }

        if ($user->hasRole('store_staff')) {
            $staffCanDiscount = config('payment.discount_types.discount.approval_roles', []);

            if (! in_array('store_staff', $staffCanDiscount, true)) {
                return false;
            }

            return $storeId === null || $user->stores()->where('store_id', $storeId)->exists();
        }

        return false;
    }

    public function canApproveAmount(User $user, string $discountType, float $amount): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        $maxAmount = $discountConfig['max_amount'] ?? 0;

        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('store_owner')) {
            return $amount <= $maxAmount;
        }

        if ($user->hasRole('store_staff')) {
            $staffMaxAmount = min($maxAmount, config('payment.auto_discount.max_amount', 100));

            return $amount <= $staffMaxAmount;
        }

        return false;
    }

    public function requiresApproval(string $discountType, float $amount): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return true;
        }

        if ($discountConfig['requires_approval'] ?? false) {
            return true;
        }

        return $amount > config('payment.auto_discount.max_amount', 100);
    }
}
```

- [ ] **Step 4: Convert middleware to an HTTP adapter**

In `CheckDiscountPermission`, add a constructor:

```php
public function __construct(private readonly DiscountPermissionService $discountPermissions) {}
```

Add:

```php
use App\Services\DiscountPermissionService;
```

Replace calls to private permission methods with:

```php
$hasPermission = $this->discountPermissions->hasDiscountTypePermission($user, $discountType, $storeId);
```

and:

```php
if (! $this->discountPermissions->hasGeneralDiscountPermission($user, $storeId)) {
```

Delete the private `checkDiscountTypePermission()`, private `hasGeneralDiscountPermission()`, and public static `checkDiscountAmount()` / `requiresApproval()` methods from the middleware.

- [ ] **Step 5: Inject the domain service into PaymentDiscountService**

Add a property and constructor to `PaymentDiscountService`:

```php
public function __construct(private readonly ?DiscountPermissionService $discountPermissions = null) {}

private function discountPermissions(): DiscountPermissionService
{
    return $this->discountPermissions ?? app(DiscountPermissionService::class);
}
```

Replace `\App\Http\Middleware\CheckDiscountPermission::checkDiscountAmount($user, $discountType, $amount)` with:

```php
$this->discountPermissions()->canApproveAmount($user, $discountType, $amount)
```

Replace `\App\Http\Middleware\CheckDiscountPermission::requiresApproval($discountType, $amount)` with:

```php
$this->discountPermissions()->requiresApproval($discountType, $amount)
```

- [ ] **Step 6: Verify tests**

Run:

```powershell
php artisan test tests/Unit/DiscountPermissionServiceTest.php tests/Unit/PaymentDiscountServiceTest.php tests/Feature/PaymentDiscountApiTest.php --compact
```

Expected: PASS.

- [ ] **Step 7: Commit Task 2**

```powershell
git add app/Services/DiscountPermissionService.php app/Http/Middleware/CheckDiscountPermission.php app/Services/PaymentDiscountService.php tests/Unit/DiscountPermissionServiceTest.php
git commit -m "refactor: decouple discount permissions from middleware"
```

---

### Task 3: Payment Controller Service Injection and Store Flow Extraction

**Files:**
- Create: `app/Services/PaymentCreationService.php`
- Modify: `app/Http/Controllers/PaymentController.php`
- Test: `tests/Feature/PaymentDiscountApiTest.php`

- [ ] **Step 1: Add a regression test for payment creation with discounts**

Append this test to `tests/Feature/PaymentDiscountApiTest.php`:

```php
/** @test */
public function creating_payment_with_discount_still_returns_loaded_payment_after_store_flow_extraction()
{
    $this->actingAs($this->admin, 'sanctum');

    $response = $this->postJson('/api/payments', [
        'store_id' => $this->store->id,
        'customer_id' => $this->customer->id,
        'amount' => 900,
        'payment_method' => 'cash',
        'allocations' => [
            [
                'invoice_id' => $this->invoice1->id,
                'amount' => 900,
            ],
        ],
        'apply_discount' => true,
        'discount_data' => [
            [
                'invoice_id' => $this->invoice1->id,
                'amount' => 100,
                'type' => 'write_off',
                'reason' => 'P5 service extraction regression',
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '900.00')
        ->assertJsonCount(1, 'data.allocations')
        ->assertJsonCount(1, 'data.discounts');

    $this->assertDatabaseHas('payments', [
        'store_id' => $this->store->id,
        'customer_id' => $this->customer->id,
        'amount' => 900,
    ]);
}
```

- [ ] **Step 2: Run the regression test and confirm current GREEN baseline**

Run:

```powershell
php artisan test tests/Feature/PaymentDiscountApiTest.php --filter=creating_payment_with_discount_still_returns_loaded_payment_after_store_flow_extraction --compact
```

Expected: PASS on current behavior before refactor. This is a characterization test for a refactor, so a pre-existing pass is acceptable.

- [ ] **Step 3: Create the payment creation service**

Create `app/Services/PaymentCreationService.php` by moving the current transaction logic from `PaymentController::store()` into:

```php
<?php

namespace App\Services;

use App\Helpers\MoneyHelper;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentCreationService
{
    public function __construct(private readonly PaymentDiscountService $discounts) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws DiscountValidationException
     * @throws \Exception
     */
    public function create(array $validated, User $user): Payment
    {
        $customer = Customer::findOrFail($validated['customer_id']);

        if ($customer->unpaidInvoices()->count() === 0) {
            throw new DiscountValidationException('该客户没有未付清的账单', 422);
        }

        $store = Store::findOrFail($validated['store_id']);
        $paymentNumber = 'PAY-'.$store->code.'-'.date('Ymd').'-'.Str::random(5);

        return DB::transaction(function () use ($validated, $user, $customer, $paymentNumber) {
            $payment = Payment::create([
                'payment_number' => $paymentNumber,
                'store_id' => $validated['store_id'],
                'customer_id' => $validated['customer_id'],
                'received_by' => $user->id,
                'amount' => $validated['amount'],
                'allocated_amount' => 0,
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            if (! empty($validated['apply_discount']) && ! empty($validated['discount_data'])) {
                $this->createPaymentWithDiscounts($payment, $customer, $validated, $user);
                $payment->load(['allocations.invoice', 'discounts.invoice', 'customer', 'store', 'receivedBy:id,name']);
            } else {
                $this->createPaymentAllocations($payment, $validated, $user);
                $payment->load(['allocations.invoice', 'customer', 'store', 'receivedBy:id,name']);
            }

            if ($payment->relationLoaded('receivedBy')) {
                $payment->setAttribute('received_by', $payment->receivedBy);
            }

            return $payment;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createPaymentWithDiscounts(Payment $payment, Customer $customer, array $validated, User $user): void
    {
        $totalAllocated = collect($validated['allocations'] ?? [])->sum('amount');
        $totalDiscount = collect($validated['discount_data'])->sum('amount');
        $totalDebt = $customer->unpaidInvoices()->with('discounts')->get()->sum('actual_remaining_amount');
        $intendedGap = MoneyHelper::subtract($totalDebt, $validated['amount']);

        if (MoneyHelper::isGreaterThan(
            MoneyHelper::add($totalAllocated, $totalDiscount),
            MoneyHelper::add($validated['amount'], max(0, $intendedGap))
        )) {
            throw new \Exception('分配金额与优惠减免总额超过了还款金额和差额');
        }

        $this->discounts->validateDiscountRequest(
            $payment,
            $validated['discount_data'],
            $user->id,
            'create_payment',
            $validated['allocations'] ?? []
        );

        $this->createPaymentAllocations($payment, $validated, $user);

        foreach ($validated['discount_data'] as $discountItem) {
            $invoice = Invoice::findOrFail($discountItem['invoice_id']);
            $this->assertInvoiceMatchesPayment($invoice, $validated);

            $payment->createDiscount(
                $invoice,
                $discountItem['amount'],
                $discountItem['type'] ?? 'write_off',
                $discountItem['reason'] ?? '优惠抹零',
                $user->id
            );

            $invoice->refresh();
            $invoice->updateStatus();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createPaymentAllocations(Payment $payment, array $validated, User $user): void
    {
        $totalAllocated = 0;

        foreach ($validated['allocations'] ?? [] as $allocationData) {
            $invoice = Invoice::findOrFail($allocationData['invoice_id']);
            $this->assertInvoiceMatchesPayment($invoice, $validated);

            $invoice->loadMissing('discounts');
            if ($allocationData['amount'] > $invoice->actual_remaining_amount) {
                throw new \Exception("账单 {$invoice->invoice_number} 的分配金额超过了剩余未付金额");
            }

            $payment->allocateToInvoice($invoice, $allocationData['amount'], $user->id);
            $totalAllocated += $allocationData['amount'];
        }

        if ($totalAllocated > $validated['amount']) {
            throw new \Exception('分配总金额超过了还款金额');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertInvoiceMatchesPayment(Invoice $invoice, array $validated): void
    {
        if ($invoice->customer_id != $validated['customer_id'] || $invoice->store_id != $validated['store_id']) {
            throw new \Exception('账单与还款的客户或门店不匹配');
        }
    }
}
```

- [ ] **Step 4: Inject services into PaymentController**

Add a constructor to `PaymentController`:

```php
public function __construct(
    private readonly AutoAllocationService $allocations,
    private readonly PaymentCreationService $paymentCreation,
    private readonly PaymentDiscountService $discounts,
) {}
```

Replace manual constructions:

- `new AutoAllocationService` -> `$this->allocations`
- `new PaymentDiscountService` -> `$this->discounts`

Replace the body of `store()` after `$validated = $request->validated();` with:

```php
try {
    $payment = $this->paymentCreation->create($validated, $user);

    $message = ! empty($validated['apply_discount']) && ! empty($validated['discount_data'])
        ? '还款记录创建成功，已处理优惠抹零'
        : '还款记录创建成功';

    return $this->successResponse($payment, $message, 201);
} catch (DiscountValidationException $e) {
    return $this->errorResponse($e->getMessage(), $e->statusCode());
} catch (\Exception $e) {
    return $this->errorResponse($e->getMessage(), 422);
}
```

Add imports:

```php
use App\Services\PaymentCreationService;
use App\Services\DiscountValidationException;
```

- [ ] **Step 5: Verify payment behavior**

Run:

```powershell
php artisan test tests/Feature/PaymentDiscountApiTest.php --compact
```

Expected: PASS.

- [ ] **Step 6: Commit Task 3**

```powershell
git add app/Services/PaymentCreationService.php app/Http/Controllers/PaymentController.php tests/Feature/PaymentDiscountApiTest.php
git commit -m "refactor: extract payment creation flow"
```

---

### Task 4: Clean Up line_uid Migration Dead Code

**Files:**
- Modify: `database/migrations/2026_03_16_000001_add_line_uid_to_invoice_items_table.php`

- [ ] **Step 1: Run a fresh migration baseline**

Run:

```powershell
$env:APP_ENV='testing'
$env:APP_KEY='base64:2spydrvgfi6Dq/vQfjvXq6Ibjl0lHX4ZuTgoyKokk68='
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE='database/database.sqlite'
if (-not (Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
php artisan migrate:fresh --force
```

Expected: migrations complete successfully before the cleanup.

- [ ] **Step 2: Delete unreachable legacy blocks**

In `up()`, delete the `return;` and every statement after it until the method closing brace. Keep the current idempotent implementation above it.

In `down()`, delete the `return;` and every statement after it until the method closing brace. Keep the current idempotent index/column cleanup above it.

The method ends should read:

```php
        if (! $this->hasIndex('invoice_items', 'invoice_items_invoice_line_uid_unique')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->unique(['invoice_id', 'line_uid'], 'invoice_items_invoice_line_uid_unique');
            });
        }
    }
```

and:

```php
        if (Schema::hasColumn('invoice_items', 'line_uid')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('line_uid');
            });
        }
    }
```

- [ ] **Step 3: Verify fresh migration after cleanup**

Run the same fresh migration command from Step 1.

Expected: migrations complete successfully.

- [ ] **Step 4: Commit Task 4**

```powershell
git add database/migrations/2026_03_16_000001_add_line_uid_to_invoice_items_table.php
git commit -m "chore: remove unreachable line uid migration code"
```

---

### Task 5: CI, Static Analysis, and Abandoned Dependency Cleanup

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `phpstan.neon`
- Create: `.github/workflows/backend-ci.yml`
- Delete: `config/scribe.php`
- Delete: `update-docs.bat`
- Delete: `update-api-docs.bat`
- Delete: `generate-markdown-docs.php`

- [ ] **Step 1: Remove the abandoned dependency source**

Run:

```powershell
composer remove --dev knuckleswtf/scribe --no-interaction
```

Expected: `knuckleswtf/scribe`, `spatie/data-transfer-object`, and Scribe-only transitive packages are removed from `composer.lock`.

- [ ] **Step 2: Delete obsolete Scribe-only files**

Delete:

```text
config/scribe.php
update-docs.bat
update-api-docs.bat
generate-markdown-docs.php
```

Keep `API_DOCUMENTATION.md`.

- [ ] **Step 3: Add Larastan**

Run:

```powershell
composer require --dev "larastan/larastan:^3.0" --no-interaction
```

Expected: `larastan/larastan`, `phpstan/phpstan`, and `phpmyadmin/sql-parser` are added to `composer.lock`.

- [ ] **Step 4: Add Composer scripts**

In `composer.json`, add these entries under `scripts`:

```json
"test": "@php artisan test --compact",
"pint:test": "pint --test",
"analyse": "phpstan analyse --memory-limit=1G",
"audit:security": "composer audit --abandoned=fail --format=json",
"platform:check": "composer check-platform-reqs"
```

Keep the existing Laravel post-install scripts.

- [ ] **Step 5: Create initial phpstan.neon**

Create `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon

parameters:
    paths:
        - app
        - routes
        - config
        - database/factories
        - database/seeders

    level: 0

    excludePaths:
        - vendor
        - storage
        - bootstrap/cache
```

- [ ] **Step 6: Create GitHub Actions workflow**

Create `.github/workflows/backend-ci.yml`:

```yaml
name: Backend CI

on:
  pull_request:
    paths:
      - '**.php'
      - 'composer.json'
      - 'composer.lock'
      - 'phpunit.xml'
      - 'phpstan.neon'
      - '.github/workflows/backend-ci.yml'
  push:
    branches:
      - main
    paths:
      - '**.php'
      - 'composer.json'
      - 'composer.lock'
      - 'phpunit.xml'
      - 'phpstan.neon'
      - '.github/workflows/backend-ci.yml'

jobs:
  backend:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: bcmath, pdo_sqlite
          coverage: none

      - name: Validate Composer files
        run: composer validate --strict

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Check platform requirements
        run: composer platform:check

      - name: Run tests
        run: composer test

      - name: Run Pint style check
        run: composer pint:test

      - name: Run static analysis
        run: composer analyse

      - name: Audit dependencies
        run: composer audit:security
```

- [ ] **Step 7: Verify tooling**

Run:

```powershell
composer validate --strict
composer audit --abandoned=fail --format=json
composer check-platform-reqs
vendor\bin\phpstan analyse --memory-limit=1G
```

Expected: all commands exit 0. If PHPStan level 0 reports existing dynamic-model issues, add the narrowest `ignoreErrors` entry with a `path` and exact message regex; do not raise the level in this task.

- [ ] **Step 8: Commit Task 5**

```powershell
git add composer.json composer.lock phpstan.neon .github/workflows/backend-ci.yml API_DOCUMENTATION.md
git add -u config/scribe.php update-docs.bat update-api-docs.bat generate-markdown-docs.php
git commit -m "ci: add backend static analysis baseline"
```

---

### Task 6: Pint Formatting Baseline

**Files:**
- Modify: files changed by `vendor/bin/pint`

- [ ] **Step 1: Run Pint formatting**

Run:

```powershell
vendor\bin\pint
```

Expected: Pint rewrites only PHP style.

- [ ] **Step 2: Verify Pint**

Run:

```powershell
vendor\bin\pint --test
```

Expected: PASS.

- [ ] **Step 3: Commit Task 6 as format-only**

```powershell
git add .
git commit -m "style: apply backend pint baseline"
```

---

### Final Verification

Run:

```powershell
composer validate --strict
composer audit --abandoned=fail --format=json
composer check-platform-reqs
vendor\bin\phpstan analyse --memory-limit=1G
vendor\bin\pint --test
php artisan test --compact
$env:APP_ENV='testing'
$env:APP_KEY='base64:2spydrvgfi6Dq/vQfjvXq6Ibjl0lHX4ZuTgoyKokk68='
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE='database/database.sqlite'
php artisan migrate:fresh --force
```

Expected:

- Composer validation passes.
- Composer audit has no advisories and no abandoned package failures.
- Platform requirements pass in the local PHP environment.
- PHPStan exits 0 at level 0.
- Pint exits 0.
- PHPUnit exits 0. Existing PHPUnit metadata warnings may remain unless Task 6 formatting changes them; warnings are not P5 blockers.
- Fresh SQLite migrations complete from an empty database.

