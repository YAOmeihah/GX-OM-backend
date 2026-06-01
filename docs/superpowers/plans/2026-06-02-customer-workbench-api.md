# Customer Workbench API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add backend API support for the Android customer workbench UI: top metrics, yesterday comparisons, debt trend data, tab counts, and list filters.

**Architecture:** Keep `GET /api/customers` as the paginated list endpoint and extend it with customer-workbench filters and row flags. Add one focused summary endpoint on `CustomerController` for the top cards and tab counts, reusing `customer_store_stats`, `invoices`, and `payments` aggregates under the existing Sanctum route group.

**Tech Stack:** Laravel 11, PHP 8, PHPUnit feature tests, existing `CustomerController`, `CustomerStoreStatsTest`.

---

### Task 1: Customer List Filters And Row Flags

**Files:**
- Modify: `tests/Feature/CustomerStoreStatsTest.php`
- Modify: `app/Http/Controllers/CustomerController.php`

- [x] **Step 1: Write failing feature tests**

Add tests that request `GET /api/customers?store_id=...&has_debt=true`, `transaction_date=YYYY-MM-DD`, and `overdue=true`, then assert only matching customers are returned and rows expose `is_debt_customer`, `has_today_transaction`, and `is_overdue`.

- [x] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/CustomerStoreStatsTest.php --filter "customer_list_filters|customer_workbench" --compact`

Observed: customer list test failed because `has_debt` was ignored and returned 3 rows instead of 2; summary route returned 404.

- [x] **Step 3: Write minimal implementation**

Extend `CustomerController@index` after the `customer_store_stats` join with filters for `has_debt`, `transaction_date`, and `overdue`. Transform the paginated collection to append row flags without changing existing pagination shape.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/CustomerStoreStatsTest.php --filter "customer_list_filters|customer_workbench" --compact`

Observed: targeted tests passed after implementation.

### Task 2: Customer Workbench Summary

**Files:**
- Modify: `tests/Feature/CustomerStoreStatsTest.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/CustomerController.php`

- [x] **Step 1: Write failing summary endpoint test**

Add a test for `GET /api/customers/workbench-summary?store_id=...&date=2026-06-02&trend_days=3` asserting `debt`, `debt_customers`, `today_payments`, and `tabs` payloads.

- [x] **Step 2: Run test and verify RED**

Run: `php artisan test tests/Feature/CustomerStoreStatsTest.php --filter "customer_list_filters|customer_workbench" --compact`

Observed: summary route returned 404 before implementation.

- [x] **Step 3: Write minimal implementation**

Register `customers/workbench-summary` before `Route::apiResource('customers', ...)`. Add `CustomerController@workbenchSummary` using existing store permission helpers, `customer_store_stats`, `payments`, and `invoices` aggregates.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/CustomerStoreStatsTest.php --filter "customer_list_filters|customer_workbench" --compact`

Observed: targeted tests passed after fixing the debt snapshot aggregate to use a subquery.

### Task 3: Final Verification

**Files:**
- All modified files

- [x] **Step 1: Run focused feature tests**

Run: `php artisan test tests/Feature/CustomerStoreStatsTest.php --compact`

Observed: 10 tests completed with 58 assertions and existing `file_get_contents` warnings.

- [x] **Step 2: Run style check**

Run: `vendor/bin/pint --test app/Http/Controllers/CustomerController.php app/Services/CustomerWorkbenchService.php routes/api.php tests/Feature/CustomerStoreStatsTest.php`

Observed: PASS for 4 files after applying Pint formatting.

- [x] **Step 3: Review route list**

Run: `php artisan route:list --path=customers`

Observed: `GET api/customers/workbench-summary` is registered before `api/customers/{customer}`.

### Review Follow-Up

- [x] Historical debt snapshots now subtract `payment_allocations` and `payment_discounts` only up to the snapshot day, so today's allocations no longer rewrite yesterday's debt.
- [x] Customer list row flags batch-load overdue customer ids for the current page instead of querying once per customer.
- [x] `date`, `trend_days`, and `transaction_date` inputs are validated and invalid dates return 422.
- [x] Summary debt amount and debt customer count are computed from one debt snapshot aggregate per day to reduce duplicate scans.
- [x] Debt trend no longer runs the historical debt snapshot once per trend day. It loads invoices, allocations, and discounts once for the requested period and builds the curve in memory.
- [x] Customer workbench aggregation and row flag logic moved out of `CustomerController` into `CustomerWorkbenchService`; an architecture regression test guards that the debt snapshot/trend methods do not return to the controller.

Final verification:
- `php artisan test tests/Feature/CustomerStoreStatsTest.php --compact` passed with 10 warnings from pre-existing `file_get_contents` calls and 58 assertions.
- `vendor/bin/pint --test app/Http/Controllers/CustomerController.php app/Services/CustomerWorkbenchService.php routes/api.php tests/Feature/CustomerStoreStatsTest.php` passed for 4 files.
