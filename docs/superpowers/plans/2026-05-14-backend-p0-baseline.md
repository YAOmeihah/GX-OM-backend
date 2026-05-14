# Backend P0 Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the P0 backend safety baseline so tests run against an isolated test database and default factories/API validation no longer depend on removed fields or invalid enum values.

**Architecture:** Keep changes surgical. Use phpunit environment isolation for the test database, fix factories so generated models satisfy current schema and store/customer invariants, remove runtime/test validation references to deleted date columns, and declare the platform extension used by money helpers.

**Tech Stack:** Laravel 11, PHP 8.2, PHPUnit 11, Eloquent factories, Laravel FormRequest, Composer.

---

## Scope

This plan covers only P0 items from `BACKEND_FIX_PLAN.md`:

- `BE-020` PHPUnit uses the real `.env` database.
- `BE-001` Customer/Invoice/Payment factories generate missing or inconsistent `store_id` data.
- `BE-041` deleted `invoice_date` / `payment_date` fields are still referenced by runtime code, tests, and docs.
- `BE-046` `PaymentFactory` generates payment methods not accepted by production requests.
- `BE-018` `MoneyHelper` uses bcmath but Composer does not declare `ext-bcmath`.

Do not fix P1+ authorization, discount, maintenance, attachment, or refactor issues in this plan.

## Files

- Modify: `phpunit.xml`
- Modify: `composer.json`
- Modify: `database/factories/CustomerFactory.php`
- Modify: `database/factories/InvoiceFactory.php`
- Modify: `database/factories/PaymentFactory.php`
- Modify: `app/Http/Requests/Invoice/UpdateInvoiceRequest.php`
- Modify: `app/Http/Requests/Invoice/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/Payment/StorePaymentRequest.php`
- Modify: `tests/Unit/FormRequestTest.php`
- Modify: P0-affected feature tests that submit removed date fields, currently including `tests/Feature/FinancialScenarioTest.php` and `tests/Feature/PaymentDiscountApiTest.php`
- Modify: docs that document removed date request/response fields, currently `API_DOCUMENTATION.md` and likely `docs/API.md`

## Task 1: Isolate PHPUnit From `.env` Database (`BE-020`)

**Files:**
- Modify: `phpunit.xml`

- [ ] **Step 1: Write the failing guard test by running a config assertion without changing production code**

Run:

```powershell
php -r "[xml]$xml = Get-Content phpunit.xml; $envs = @{}; $xml.phpunit.php.env | ForEach-Object { $envs[$_.name] = $_.value }; if (($envs['DB_CONNECTION'] -ne 'sqlite') -or ($envs['DB_DATABASE'] -ne ':memory:')) { Write-Error 'phpunit.xml does not isolate the test database'; exit 1 };"
```

Expected: FAIL with `phpunit.xml does not isolate the test database`.

- [ ] **Step 2: Add explicit test DB environment settings**

In `phpunit.xml`, inside `<php>`, add:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Keep the existing `APP_ENV`, cache, queue, session, mail, Pulse, and Telescope entries.

- [ ] **Step 3: Verify the guard now passes**

Run:

```powershell
php -r "[xml]$xml = Get-Content phpunit.xml; $envs = @{}; $xml.phpunit.php.env | ForEach-Object { $envs[$_.name] = $_.value }; if (($envs['DB_CONNECTION'] -ne 'sqlite') -or ($envs['DB_DATABASE'] -ne ':memory:')) { Write-Error 'phpunit.xml does not isolate the test database'; exit 1 }; Write-Output 'phpunit.xml isolates the test database';"
```

Expected: PASS and prints `phpunit.xml isolates the test database`.

- [ ] **Step 4: Run a small PHPUnit smoke test**

Run:

```powershell
php artisan test tests/Unit/FormRequestTest.php --filter store_payment_request_validates_required_fields
```

Expected before later P0 tasks: this may still fail due to removed date-field expectations. Record the failure and continue to Task 4.

- [ ] **Step 5: Commit**

```powershell
git add phpunit.xml
git commit -m "test: isolate backend phpunit database"
```

## Task 2: Declare bcmath Platform Dependency (`BE-018`)

**Files:**
- Modify: `composer.json`
- Generated/updated by Composer if available: `composer.lock`

- [ ] **Step 1: Write the failing platform requirement check**

Run:

```powershell
php -r "$composer = json_decode(file_get_contents('composer.json'), true); if (!isset($composer['require']['ext-bcmath'])) { fwrite(STDERR, 'composer.json is missing ext-bcmath'); exit(1); }"
```

Expected: FAIL with `composer.json is missing ext-bcmath`.

- [ ] **Step 2: Add `ext-bcmath` to Composer requirements**

Use Composer if available:

```powershell
composer require ext-bcmath:* --no-update
```

If Composer cannot run, edit `composer.json` manually under `"require"`:

```json
"ext-bcmath": "*"
```

Keep Composer's existing sorted-package style if it rewrites the file.

- [ ] **Step 3: Refresh lock metadata if Composer is available**

Run:

```powershell
composer update --lock --no-install
```

Expected: Composer updates only lock metadata and does not install packages.

- [ ] **Step 4: Verify platform requirements**

Run:

```powershell
composer check-platform-reqs
```

Expected: PASS if local PHP has bcmath installed. If local PHP lacks bcmath, expected failure should explicitly name `ext-bcmath`; record it as an environment issue, not an implementation failure.

- [ ] **Step 5: Commit**

```powershell
git add composer.json composer.lock
git commit -m "chore: declare bcmath platform dependency"
```

## Task 3: Fix Factory Store Consistency and Payment Enum (`BE-001`, `BE-046`)

**Files:**
- Modify: `database/factories/CustomerFactory.php`
- Modify: `database/factories/InvoiceFactory.php`
- Modify: `database/factories/PaymentFactory.php`
- Test: create or extend a focused factory test, preferably `tests/Unit/FactoryBaselineTest.php`

- [ ] **Step 1: Write failing tests for default factory invariants**

Create `tests/Unit/FactoryBaselineTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_factory_creates_customer_with_store(): void
    {
        $customer = Customer::factory()->create();

        $this->assertNotNull($customer->store_id);
        $this->assertDatabaseHas('stores', ['id' => $customer->store_id]);
    }

    public function test_invoice_factory_uses_customer_store_by_default(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame($invoice->customer->store_id, $invoice->store_id);
    }

    public function test_payment_factory_uses_customer_store_and_valid_method_by_default(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame($payment->customer->store_id, $payment->store_id);
        $this->assertContains($payment->payment_method, ['cash', 'bank_transfer', 'wechat', 'alipay', 'other']);
        $this->assertFalse($payment->isDirty());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
php artisan test tests/Unit/FactoryBaselineTest.php
```

Expected: FAIL because `CustomerFactory` omits `store_id`, and/or `InvoiceFactory` / `PaymentFactory` create customer and store independently, and `PaymentFactory` may emit unsupported enum values.

- [ ] **Step 3: Fix `CustomerFactory`**

Add `Store` import:

```php
use App\Models\Store;
```

Add `store_id` to the returned array in `definition()`:

```php
'store_id' => Store::factory(),
```

- [ ] **Step 4: Fix `InvoiceFactory` default consistency**

Change `InvoiceFactory::definition()` so it creates one store-backed customer and reuses the same store:

```php
$customer = Customer::factory()->create();
```

Then use:

```php
'store_id' => $customer->store_id,
'customer_id' => $customer->id,
```

Keep `created_by`, `amount`, `paid_amount`, `status`, `due_date`, and `description` behavior unchanged.

- [ ] **Step 5: Fix `PaymentFactory` default consistency and enum**

Change `PaymentFactory::definition()` so it creates one store-backed customer and reuses the same store:

```php
$customer = Customer::factory()->create();
```

Then use:

```php
'store_id' => $customer->store_id,
'customer_id' => $customer->id,
```

Remove the removed date column from factory output:

```php
// remove 'payment_date'
```

Change `payment_method` values to production-accepted values:

```php
'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'wechat', 'alipay', 'other']),
```

- [ ] **Step 6: Run focused factory tests**

Run:

```powershell
php artisan test tests/Unit/FactoryBaselineTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add database/factories/CustomerFactory.php database/factories/InvoiceFactory.php database/factories/PaymentFactory.php tests/Unit/FactoryBaselineTest.php
git commit -m "test: stabilize backend model factories"
```

## Task 4: Remove Deleted Date Fields From Runtime Validation and Tests (`BE-041`)

**Files:**
- Modify: `app/Http/Requests/Invoice/UpdateInvoiceRequest.php`
- Modify: `app/Http/Requests/Invoice/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/Payment/StorePaymentRequest.php`
- Modify: `tests/Unit/FormRequestTest.php`
- Modify: `tests/Feature/FinancialScenarioTest.php`
- Modify: `tests/Feature/PaymentDiscountApiTest.php`
- Inspect and modify if needed: `app/Http/Controllers/DashboardController.php`, `app/Models/Customer.php`, `app/Services/Audit/InvoiceAuditDiffBuilder.php`

- [ ] **Step 1: Write or update failing FormRequest expectations**

In `tests/Unit/FormRequestTest.php`, update the request rule tests so they assert deleted fields are absent:

```php
$this->assertArrayNotHasKey('payment_date', $rules);
$this->assertArrayNotHasKey('invoice_date', $rules);
```

For valid request payload tests, remove `payment_date` and `invoice_date` from payloads.

- [ ] **Step 2: Run FormRequest tests to verify current code fails**

Run:

```powershell
php artisan test tests/Unit/FormRequestTest.php
```

Expected: FAIL because `UpdateInvoiceRequest` still exposes `invoice_date` and messages still reference deleted fields.

- [ ] **Step 3: Remove deleted date validation from `UpdateInvoiceRequest`**

In `UpdateInvoiceRequest::rules()`, remove:

```php
'invoice_date' => 'sometimes|required',
'due_date' => 'nullable|date|after_or_equal:invoice_date',
```

Replace with:

```php
'due_date' => 'nullable|date',
```

In `UpdateInvoiceRequest::messages()`, remove `invoice_date.*` messages.

- [ ] **Step 4: Clean stale messages in store request classes**

In `StoreInvoiceRequest::messages()`, remove `invoice_date.*` messages because `StoreInvoiceRequest::rules()` no longer validates `invoice_date`.

In `StorePaymentRequest::messages()`, remove `payment_date.*` messages because `StorePaymentRequest::rules()` no longer validates `payment_date`.

- [ ] **Step 5: Update feature tests that still submit deleted fields**

In `tests/Feature/FinancialScenarioTest.php` and `tests/Feature/PaymentDiscountApiTest.php`, remove `invoice_date` and `payment_date` from request payload arrays. Do not replace them with new fields unless existing controller/request rules require it.

- [ ] **Step 6: Fix runtime references that still query removed columns**

Search:

```powershell
rg -n "invoice_date|payment_date" app tests
```

For runtime code under `app/`, replace removed column references with current columns only where behavior is clear:

- Date filtering on invoices/payments should use `created_at` unless a more specific current date field exists.
- Sorting customer payment history should use `created_at` unless a current payment date field exists.
- Audit field labels should remove deleted fields from diffable field lists.

Do not modify old migrations that create/drop historical date columns; they are migration history and not P0 runtime drift.

- [ ] **Step 7: Run targeted tests**

Run:

```powershell
php artisan test tests/Unit/FormRequestTest.php
php artisan test tests/Feature/FinancialScenarioTest.php
php artisan test tests/Feature/PaymentDiscountApiTest.php
```

Expected: P0-related date-field failures are gone. Remaining failures from P2 discount behavior may still exist; record them and do not broaden scope.

- [ ] **Step 8: Commit**

```powershell
git add app/Http/Requests/Invoice/UpdateInvoiceRequest.php app/Http/Requests/Invoice/StoreInvoiceRequest.php app/Http/Requests/Payment/StorePaymentRequest.php tests/Unit/FormRequestTest.php tests/Feature/FinancialScenarioTest.php tests/Feature/PaymentDiscountApiTest.php app/Http/Controllers/DashboardController.php app/Models/Customer.php app/Services/Audit/InvoiceAuditDiffBuilder.php
git commit -m "fix: remove deleted date fields from backend runtime"
```

Only include the optional runtime files in `git add` if they were changed.

## Task 5: Update API Documentation for Removed Date Fields (`BE-041`)

**Files:**
- Modify: `API_DOCUMENTATION.md`
- Modify: `docs/API.md` if it contains the same stale fields

- [ ] **Step 1: Write the failing doc drift check**

Run:

```powershell
rg -n "invoice_date|payment_date" API_DOCUMENTATION.md docs/API.md
```

Expected: FAIL-like output showing stale documented fields.

- [ ] **Step 2: Remove deleted date fields from docs**

In invoice create/update/list examples and parameter tables:

- Remove `invoice_date` request fields.
- Remove `invoice_date` response examples unless they are explicitly historical migration notes.
- If prose needs a replacement, say invoices use `created_at` for creation timestamp.

In payment create/list examples and parameter tables:

- Remove `payment_date` request fields.
- Remove `payment_date` response examples unless they are explicitly historical migration notes.
- If prose needs a replacement, say payments use `created_at` for creation timestamp.

- [ ] **Step 3: Verify doc drift is gone in active API docs**

Run:

```powershell
rg -n "invoice_date|payment_date" API_DOCUMENTATION.md docs/API.md
```

Expected: no matches in active API documentation. If matches remain only in explicit migration/history notes, verify they are not request/response contract examples.

- [ ] **Step 4: Commit**

```powershell
git add API_DOCUMENTATION.md docs/API.md
git commit -m "docs: remove deleted date fields from API docs"
```

## Task 6: Run P0 Verification Suite

**Files:**
- No new code changes expected.

- [ ] **Step 1: Run platform dependency check**

```powershell
composer check-platform-reqs
```

Expected: PASS, or explicit environment failure for missing `ext-bcmath`.

- [ ] **Step 2: Run P0 targeted tests**

```powershell
php artisan test tests/Unit/FactoryBaselineTest.php
php artisan test tests/Unit/FormRequestTest.php
```

Expected: PASS.

- [ ] **Step 3: Run broader backend test baseline**

```powershell
php artisan test --compact
```

Expected: no failures caused by test DB isolation, missing `customers.store_id`, inconsistent factory store/customer data, removed date fields, or invalid `PaymentFactory.payment_method`. Failures belonging to P1+ issues should be listed separately.

- [ ] **Step 4: Run style check**

```powershell
vendor/bin/pint --test
```

Expected: May fail because `BE-005` tracks pre-existing Pint baseline issues. Record the current count and do not run Pint formatting in P0 unless the user explicitly approves a separate formatting commit.

- [ ] **Step 5: Update tracking docs**

Update the P0 status in `BACKEND_FIX_PLAN.md` and `BACKEND_ISSUES.md` only after verification. Mark each P0 item with one of:

- fixed and verified,
- fixed with environment caveat,
- blocked with exact command/output.

- [ ] **Step 6: Final commit**

```powershell
git add BACKEND_FIX_PLAN.md BACKEND_ISSUES.md
git commit -m "docs: record backend P0 verification status"
```

Skip this commit if no tracking docs were changed.

## Completion Criteria

- `phpunit.xml` isolates tests from the real `.env` database.
- `CustomerFactory`, `InvoiceFactory`, and `PaymentFactory` create schema-valid and store-consistent data by default.
- `PaymentFactory` only uses payment methods accepted by `StorePaymentRequest`.
- Runtime validation/tests/docs no longer expose deleted `invoice_date` or `payment_date` fields.
- Composer declares `ext-bcmath`.
- P0 targeted tests pass.
- Any remaining full-suite failures are classified as non-P0 and mapped back to `BACKEND_FIX_PLAN.md`.

