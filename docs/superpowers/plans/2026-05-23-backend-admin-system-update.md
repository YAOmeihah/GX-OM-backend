# Backend Admin System Update Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a manual admin-triggered backend update flow that checks GitHub Releases, downloads and verifies the backend `.tar.gz`, applies it safely to the current file-based deployment, and exposes progress in GX-OM Admin.

**Architecture:** Build a backend update module with a run table, lock, GitHub release client, verifier, and in-place installer that preserves `.env`, `storage/`, and `public/storage`. Expose it through dedicated `/api/system-updates/*` endpoints, then add a system page in GX-OM Admin that checks for updates and starts an update run with progress polling.

**Tech Stack:** Laravel 11, PHP 8.2, GitHub Releases API, Laravel Queue or synchronous run orchestration, MySQL/MariaDB, Vue 3, TypeScript, Ant Design Vue, Vben routes and API wrappers.

---

### Task 1: Add backend update persistence and permission plumbing

**Files:**
- Modify: `database/seeders/PermissionSeeder.php`
- Create: `database/migrations/2026_05_23_000001_create_system_update_runs_table.php`
- Create: `app/Models/SystemUpdateRun.php`
- Modify: `database/seeders/DatabaseSeeder.php` if the new permission needs to be seeded in existing flows
- Test: `tests/Feature/SystemUpdatePermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_system_update_permission_is_seeded_for_admin_management(): void
{
    $this->seed(PermissionSeeder::class);

    $permission = Permission::where('slug', 'system-updates.manage')->first();

    $this->assertNotNull($permission);
    $this->assertSame('system', $permission->module);
}
```

```php
public function test_system_update_run_table_persists_status_and_logs(): void
{
    $run = SystemUpdateRun::create([
        'tag' => 'v1.2.3',
        'version' => '1.2.3',
        'status' => 'pending',
        'actor_user_id' => 1,
        'log_lines' => [],
    ]);

    $this->assertSame('pending', $run->status);
    $this->assertSame('v1.2.3', $run->tag);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SystemUpdatePermissionTest.php --compact`
Expected: fail because the permission and model/table do not exist yet.

- [ ] **Step 3: Write minimal implementation**

```php
// PermissionSeeder.php: add one permission row
['name' => '系统更新', 'slug' => 'system-updates.manage', 'module' => 'system', 'description' => '检查、下载、安装和回滚系统更新'],
```

```php
// migration sketch
Schema::create('system_update_runs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('tag');
    $table->string('version')->nullable();
    $table->string('status')->index();
    $table->string('step')->nullable();
    $table->json('metadata')->nullable();
    $table->json('log_lines')->nullable();
    $table->string('backup_path')->nullable();
    $table->string('package_path')->nullable();
    $table->string('package_sha256')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamps();
});
```

```php
// model sketch
class SystemUpdateRun extends Model
{
    protected $fillable = [
        'actor_user_id','tag','version','status','step','metadata','log_lines',
        'backup_path','package_path','package_sha256','error_message','started_at','finished_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'log_lines' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SystemUpdatePermissionTest.php --compact`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/PermissionSeeder.php database/migrations/*_create_system_update_runs_table.php app/Models/SystemUpdateRun.php tests/Feature/SystemUpdatePermissionTest.php
git commit -m "feat: add system update persistence"
```

### Task 2: Add backend release resolution and verification services

**Files:**
- Create: `app/Services/SystemUpdate/GitHubReleaseClient.php`
- Create: `app/Services/SystemUpdate/ReleasePackageVerifier.php`
- Create: `app/Services/SystemUpdate/SystemUpdateService.php`
- Create: `config/system_update.php`
- Test: `tests/Feature/SystemUpdateReleaseCheckTest.php`
- Test: `tests/Unit/ReleasePackageVerifierTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_check_endpoint_reports_new_release_against_local_version(): void
{
    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v1.2.4',
            'draft' => false,
            'prerelease' => false,
            'assets' => [
                ['name' => 'release-manifest.json', 'browser_download_url' => 'https://example.test/release-manifest.json'],
                ['name' => 'gx-om-backend-v1.2.4.tar.gz', 'browser_download_url' => 'https://example.test/pkg.tar.gz'],
            ],
        ]),
        'example.test/*' => Http::response('{"version":"1.2.4"}'),
    ]);

    $response = $this->getJson('/api/system-updates/check');

    $response->assertOk();
    $response->assertJsonPath('data.latest.tag', 'v1.2.4');
    $response->assertJsonPath('data.has_update', true);
}
```

```php
public function test_verifier_rejects_tarball_traversal(): void
{
    $verifier = new ReleasePackageVerifier();
    $this->expectException(UnexpectedValueException::class);
    $verifier->assertSafeArchive('/tmp/unsafe.tar.gz');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SystemUpdateReleaseCheckTest.php tests/Unit/ReleasePackageVerifierTest.php --compact`
Expected: fail because the service classes and endpoints do not exist yet.

- [ ] **Step 3: Write minimal implementation**

```php
// GitHubReleaseClient should:
// - call GitHub Releases API using configured owner/repo
// - resolve latest non-draft release
// - pick release-manifest.json, tar.gz, and tar.gz.sha256 assets
// - return sanitized metadata for the admin page
```

```php
// ReleasePackageVerifier should:
// - verify tag matches ^v\d+\.\d+\.\d+
// - verify sha256
// - reject paths outside the archive root
// - assert required entries exist
// - reject blocked dev-only entries
```

```php
// config/system_update.php should define:
return [
    'github' => [
        'owner' => env('SYSTEM_UPDATE_GITHUB_OWNER', 'YAOmeihah'),
        'repo' => env('SYSTEM_UPDATE_GITHUB_REPO', 'GX-OM-backend'),
    ],
    'post_update_commands' => [],
    'backup_limit' => 3,
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SystemUpdateReleaseCheckTest.php tests/Unit/ReleasePackageVerifierTest.php --compact`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SystemUpdate/GitHubReleaseClient.php app/Services/SystemUpdate/ReleasePackageVerifier.php app/Services/SystemUpdate/SystemUpdateService.php config/system_update.php tests/Feature/SystemUpdateReleaseCheckTest.php tests/Unit/ReleasePackageVerifierTest.php
git commit -m "feat: resolve and verify backend releases"
```

### Task 3: Add in-place install and rollback behavior

**Files:**
- Create: `app/Services/SystemUpdate/InPlaceReleaseInstaller.php`
- Create: `app/Jobs/SystemUpdateJob.php` if queued execution is used
- Modify: `app/Services/SystemUpdate/SystemUpdateService.php`
- Test: `tests/Feature/SystemUpdateInstallTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_install_preserves_env_storage_and_public_storage(): void
{
    Storage::fake();
    // create a fixture deployment root under a temp directory and point the installer to it

    $response = $this->postJson('/api/system-updates/install', [
        'tag' => 'v1.2.4',
        'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'confirmed' => true,
    ]);

    $response->assertAccepted();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SystemUpdateInstallTest.php --compact`
Expected: fail because install orchestration and update endpoints do not exist yet.

- [ ] **Step 3: Write minimal implementation**

```php
// InPlaceReleaseInstaller should:
// - create storage/app/system_updates/{downloads,staging,backups,runs}
// - download the tar.gz
// - verify sha256 before extract
// - extract to staging
// - back up current managed entries
// - copy new managed entries in place
// - preserve .env, storage/, and public/storage
// - run php artisan down/migrate --force/optimize:clear/storage:link/up
// - restore backup and try php artisan up on failure
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SystemUpdateInstallTest.php --compact`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SystemUpdate/InPlaceReleaseInstaller.php app/Services/SystemUpdate/SystemUpdateService.php app/Jobs/SystemUpdateJob.php tests/Feature/SystemUpdateInstallTest.php
git commit -m "feat: install backend releases in place"
```

### Task 4: Add backend system update API routes and controller

**Files:**
- Create: `app/Http/Controllers/SystemUpdateController.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/ApiController.php` if a small shared response helper is needed
- Test: `tests/Feature/SystemUpdateApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_system_update_routes_require_manage_permission(): void
{
    $this->actingAs($this->storeStaff);

    $this->getJson('/api/system-updates/current')->assertForbidden();
    $this->getJson('/api/system-updates/check')->assertForbidden();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SystemUpdateApiTest.php --compact`
Expected: fail because controller/routes are missing.

- [ ] **Step 3: Write minimal implementation**

```php
// Controller endpoints:
// current, check, install, runs index, runs show, rollback
// all gated by auth:sanctum + permission middleware
```

```php
// routes/api.php should group:
Route::prefix('system-updates')->middleware(['auth:sanctum', 'permission:system-updates.manage'])->group(function () {
    Route::get('/current', [SystemUpdateController::class, 'current']);
    Route::get('/check', [SystemUpdateController::class, 'check']);
    Route::post('/install', [SystemUpdateController::class, 'install']);
    Route::get('/runs', [SystemUpdateController::class, 'index']);
    Route::get('/runs/{run}', [SystemUpdateController::class, 'show']);
    Route::post('/rollback', [SystemUpdateController::class, 'rollback']);
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SystemUpdateApiTest.php --compact`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SystemUpdateController.php routes/api.php tests/Feature/SystemUpdateApiTest.php
git commit -m "feat: expose backend system update api"
```

### Task 5: Add GX-OM Admin API client and system route

**Files:**
- Create: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/api/modules/system-update.ts`
- Modify: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/router/routes/modules/system.ts`
- Test: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/router/routes/modules/admin-authority.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
it('exposes system update route with system-updates.manage authority', () => {
  const updateRoute = routes[0].children?.find((route) => route.path === '/system/update');

  expect(updateRoute?.meta?.authority).toContain('system-updates.manage');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm -F @vben/web-antd test src/router/routes/modules/admin-authority.test.ts`
Expected: fail because the route and authority are missing.

- [ ] **Step 3: Write minimal implementation**

```ts
// src/api/modules/system-update.ts
export interface SystemUpdateCurrentResponse { /* current version fields */ }
export interface SystemUpdateCheckResponse { /* latest release fields */ }
export interface SystemUpdateRun { /* status, step, logs, timestamps */ }

export function getSystemUpdateCurrentApi() { return requestClient.get('/system-updates/current'); }
export function checkSystemUpdateApi() { return requestClient.get('/system-updates/check'); }
export function installSystemUpdateApi(payload: { tag: string; sha256: string; confirmed: true }) {
  return requestClient.post('/system-updates/install', payload);
}
export function getSystemUpdateRunsApi() { return requestClient.get('/system-updates/runs'); }
export function getSystemUpdateRunApi(id: number) { return requestClient.get(`/system-updates/runs/${id}`); }
export function rollbackSystemUpdateApi(payload: { run_id?: number }) {
  return requestClient.post('/system-updates/rollback', payload);
}
```

```ts
// src/router/routes/modules/system.ts
{
  name: 'SystemUpdate',
  path: '/system/update',
  component: () => import('#/views/system/update/index.vue'),
  meta: {
    icon: 'lucide:refresh-cw',
    title: $t('page.system.update'),
    authority: ['system-updates.manage'],
  },
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm -F @vben/web-antd test src/router/routes/modules/admin-authority.test.ts`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add src/api/modules/system-update.ts src/router/routes/modules/system.ts src/router/routes/modules/admin-authority.test.ts
git commit -m "feat: add admin system update entry"
```

### Task 6: Build the GX-OM Admin update page

**Files:**
- Create: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/views/system/update/index.vue`
- Modify: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/locales/langs/zh-CN/page.json`
- Modify: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/locales/langs/en-US/page.json`
- Test: `D:/Hrlni/Desktop/GX-OM/GX-OM-admin/apps/web-antd/src/views/system/update/__tests__/index.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
it('shows current version and update actions', async () => {
  // render the page with mocked API responses
  // assert current version, latest release card, check button, update button
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm -F @vben/web-antd test src/views/system/update/__tests__/index.spec.ts`
Expected: fail because the view does not exist yet.

- [ ] **Step 3: Write minimal implementation**

```vue
<!-- update page should:
     - fetch current version on mount
     - allow manual check
     - show latest release metadata
     - confirm before install
     - poll run status during install
     - show recent history and failure logs -->
```

```json
// zh-CN/page.json
{
  "system": {
    "update": "系统更新"
  }
}
```

```json
// en-US/page.json
{
  "system": {
    "update": "System Update"
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm -F @vben/web-antd test src/views/system/update/__tests__/index.spec.ts`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add src/views/system/update/index.vue src/locales/langs/zh-CN/page.json src/locales/langs/en-US/page.json
git commit -m "feat: add admin system update page"
```

### Task 7: Run end-to-end validation against a fixture deployment root

**Files:**
- Use: backend updater services and admin page
- Test: add or extend `tests/Feature/SystemUpdateE2ETest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_update_flow_preserves_env_and_storage_on_fixture_root(): void
{
    // create a temp fixture deployment root with .env, storage, and public/storage
    // run the installer against that root
    // assert updated runtime files and preserved live data
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SystemUpdateE2ETest.php --compact`
Expected: fail until the end-to-end flow is complete.

- [ ] **Step 3: Write minimal implementation**

```php
// fixture root should include:
// - .env
// - storage/app/private
// - storage/app/public
// - storage/logs
// - public/storage
// - current managed runtime files
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SystemUpdateE2ETest.php --compact`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/SystemUpdateE2ETest.php
git commit -m "test: cover backend admin update flow"
```
