# Backend P5 Maintenance Design

## Scope

This P5 pass covers low-risk backend engineering cleanup from the repository-level repair plan:

- `BE-003`: reduce controller-owned business logic in the payment creation path.
- `BE-005`: restore the Pint style baseline in a separate formatting commit.
- `BE-006`: add CI and a low-level static-analysis baseline.
- `BE-007`: remove the abandoned Composer dependency path.
- `BE-012`: stop querying the permissions table directly during provider boot without guards or cache.
- `BE-013`: remove the domain-service dependency on HTTP middleware and stop manual service construction in `PaymentController`.
- `BE-049`: delete unreachable `line_uid` migration code after the current idempotent implementation.

Baseline in isolated worktree `fix/p5-backend-maintenance`:

- `composer install`: pass.
- `php artisan test --compact`: exit 0 with PHPUnit 12 doc-comment metadata warnings.
- `vendor/bin/pint --test`: exit 0 on current HEAD.
- `composer validate --strict`: pass.
- `composer audit --format=json`: exit 1 because `knuckleswtf/scribe` pulls abandoned `spatie/data-transfer-object`.

## Approach Options

### Option A: Broad Controller Rewrite

Extract payment, invoice, customer, and public-share flows in one pass. This would address more of `BE-003`, but it is too broad for a low-risk P5 batch because it touches several business paths after P0-P4 fixes.

### Option B: Targeted Engineering Slices

Make only behavior-preserving seams: provider boot safety, discount permission service extraction, payment controller dependency injection, one payment creation service extraction, CI/static analysis, dependency cleanup, migration dead-code removal, and final formatting. This is the selected approach because it improves architecture without reopening high-risk business rules.

### Option C: Tooling Only

Limit the batch to CI, Pint, composer audit, and migration cleanup. This is safest, but it leaves `BE-003`, `BE-012`, and `BE-013` materially unresolved.

## Selected Design

Use Option B. Each implementation task has a narrow write set and a focused verification command. The only controller extraction is `PaymentController::store`, because that method contains creation, allocation, discount, transaction, relation-loading, and response-shaping logic in one controller action. Other large controllers remain indexed as future architecture debt rather than being rewritten in this batch.

## Architecture

### Permission Gate Registration

Create `App\Services\PermissionGateRegistrar` to own authorization gate bootstrapping. `AuthServiceProvider` delegates to it. The registrar always registers the admin `Gate::before`, checks `Schema::hasTable('permissions')` before reading permissions, caches permission slugs, and exposes a cache-forget method. The `Permission` model clears that cache when permission rows change.

### Discount Permission Boundary

Create `App\Services\DiscountPermissionService` for discount role, store, amount, and approval checks. `CheckDiscountPermission` becomes an HTTP adapter that extracts request context and delegates to this service. `PaymentDiscountService` depends on the new domain service instead of calling middleware static methods.

### Payment Controller Boundary

Inject `PaymentDiscountService` and `AutoAllocationService` through the `PaymentController` constructor. Extract the current payment creation transaction into `App\Services\PaymentCreationService`, keeping the controller responsible for request validation, authorization already provided by `StorePaymentRequest`, and API responses.

### Tooling and Dependency Cleanup

Add Larastan/PHPStan with an initial low-level `phpstan.neon` so static analysis exists and can be raised later. Add Composer scripts for test, Pint check, analysis, platform checks, and audit. Add a GitHub Actions workflow that installs dependencies and runs the same checks.

`knuckleswtf/scribe` is a dev-only direct dependency whose stable line currently pulls abandoned `spatie/data-transfer-object`; the available v5 development line still keeps that abandoned package. This P5 pass removes Scribe and the Scribe-only generation scripts/config so `composer audit --abandoned=fail` can pass. The existing static `API_DOCUMENTATION.md` remains.

### Migration Cleanup

Keep the current idempotent `line_uid` migration logic and delete only the unreachable legacy blocks after `return;`. Verify with a fresh SQLite migration run.

## Testing

Use TDD for behavior-bearing changes:

- Add provider tests before changing permission gate registration.
- Add discount permission service tests before replacing middleware/static calls.
- Add payment creation API coverage or a focused service test before extracting the store flow.
- Add or run a fresh migration verification before deleting unreachable migration code.

Final verification:

- `composer validate --strict`
- `composer audit --abandoned=fail --format=json`
- `composer check-platform-reqs`
- `vendor/bin/phpstan analyse --memory-limit=1G`
- `vendor/bin/pint --test`
- `php artisan test --compact`
- Fresh SQLite `php artisan migrate:fresh`
