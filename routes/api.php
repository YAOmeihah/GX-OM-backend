<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceBusinessController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrintTaskController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// 认证相关路由
Route::post('/login', [AuthController::class, 'login']);
Route::middleware(['auth:sanctum', 'role:admin'])->post('/register', [AuthController::class, 'register']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->get('/user', [AuthController::class, 'user']);
Route::middleware('auth:sanctum')->put('/user/password', [AuthController::class, 'changePassword']);

// ========== 公开接口（无需登录）==========
Route::prefix('public')->group(function () {
    Route::get('bills/{token}', [PublicInvoiceController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    // 分享链接生成（需要登录）
    Route::post('invoices/share-link', [PublicInvoiceController::class, 'createShareLink']);

    // 仪表盘相关路由
    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('dashboard/statistics', [DashboardController::class, 'statistics']);

    // 用户管理相关路由（仅管理员）
    Route::post('users', [UserController::class, 'store']);
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::put('users/{user}/roles', [UserController::class, 'updateRoles']);
    Route::put('users/{user}/stores', [UserController::class, 'updateStores']);
    Route::get('roles', [UserController::class, 'getRoles']);

    // 门店相关路由
    Route::get('stores/{store}/users', [StoreController::class, 'users']);
    Route::get('stores/{store}/payment-codes', [StoreController::class, 'paymentCodes']);
    Route::apiResource('stores', StoreController::class);

    // 客户相关路由
    Route::get('customers/workbench-summary', [CustomerController::class, 'workbenchSummary']);
    Route::apiResource('customers', CustomerController::class);

    // 需要门店权限检查的客户相关路由
    Route::middleware('customer.store.access')->group(function () {
        Route::get('customers/{customer}/debt', [CustomerController::class, 'debt']);
        Route::post('customers/{customer}/clear-debt', [CustomerController::class, 'clearDebt']);
        Route::get('customers/{customer}/daily-unpaid-summary', [CustomerController::class, 'dailyUnpaidSummary']);
        Route::post('customers/{customer}/bill-summary', [CustomerController::class, 'summaryByInvoiceIds']);
    });

    // 账单相关路由
    Route::get('invoices/summary', [InvoiceBusinessController::class, 'summary']);
    Route::get('invoices/allocatable', [InvoiceBusinessController::class, 'allocatable']);
    Route::post('invoices/print-details', [InvoiceBusinessController::class, 'printDetails']);
    Route::get('print-tasks/today-unpaid', [PrintTaskController::class, 'todayUnpaid']);
    Route::apiResource('invoices', InvoiceController::class);

    // 账单明细相关路由
    Route::get('invoices/{invoice}/items', [InvoiceItemController::class, 'index']);
    Route::post('invoices/{invoice}/items', [InvoiceItemController::class, 'store']);
    Route::put('invoice-items/{item}', [InvoiceItemController::class, 'update']);
    Route::delete('invoice-items/{item}', [InvoiceItemController::class, 'destroy']);

    // 附件管理相关路由
    Route::post('attachments/presigned-url', [AttachmentController::class, 'generatePresignedUrl']);
    Route::post('attachments', [AttachmentController::class, 'confirmUpload']);
    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // 配置管理相关路由
    Route::get('config/attachment', [ConfigController::class, 'getAttachmentConfig']);
    Route::put('config/s3', [ConfigController::class, 'updateS3Config']);
    Route::post('config/s3/test', [ConfigController::class, 'testS3Connection']);
    Route::delete('config/s3/runtime', [ConfigController::class, 'resetS3RuntimeConfig']);

    // 还款相关路由
    Route::apiResource('payments', PaymentController::class);
    Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
    Route::post('payments/{payment}/batch-allocate', [PaymentController::class, 'batchAllocate']);

    // 自动分配相关路由
    Route::get('payments/{payment}/allocation-suggestion', [PaymentController::class, 'getAllocationSuggestion']);
    Route::post('payments/{payment}/auto-allocate', [PaymentController::class, 'autoAllocate']);
    Route::post('payments/batch-auto-allocate', [PaymentController::class, 'batchAutoAllocate']);

    // 分配撤销相关路由
    Route::delete('payments/{payment}/allocations/{allocation}', [PaymentController::class, 'revokeAllocation']);
    Route::delete('payments/{payment}/allocations', [PaymentController::class, 'revokeAllAllocations']);

    // 优惠减免相关路由
    Route::middleware('discount.permission')->group(function () {
        Route::get('payments/{payment}/detect-gap', [PaymentController::class, 'detectGap']);
        Route::post('payments/{payment}/apply-discount', [PaymentController::class, 'applyDiscount']);
        Route::get('discount-statistics', [PaymentController::class, 'getDiscountStatistics']);
    });

    // 审计日志相关路由
    Route::prefix('audit-logs')->middleware('permission:audit-logs.view')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/statistics', [AuditLogController::class, 'statistics']);
        Route::get('/filters', [AuditLogController::class, 'filters']);
        Route::get('/history', [AuditLogController::class, 'history']);
        Route::get('/user-activity/{userId?}', [AuditLogController::class, 'userActivity']);
        Route::get('/{id}', [AuditLogController::class, 'show']);
    });

    // ========== 新增：权限管理相关路由 ==========
    Route::prefix('permissions')->group(function () {
        // 获取当前用户权限（所有登录用户可用）
        Route::get('/my', [\App\Http\Controllers\PermissionController::class, 'myPermissions']);

        // 验证token是否有效
        Route::get('/check-token', function () {
            return response()->json(['valid' => true]);
        });

        // 以下接口仅管理员可访问
        Route::middleware('role:admin')->group(function () {
            Route::get('/', [\App\Http\Controllers\PermissionController::class, 'index']);
            Route::get('/modules', [\App\Http\Controllers\PermissionController::class, 'getModules']);
            Route::get('/roles/{role}', [\App\Http\Controllers\PermissionController::class, 'getRolePermissions']);
            Route::put('/roles/{role}', [\App\Http\Controllers\PermissionController::class, 'updateRolePermissions']);
        });
    });

    // ========== 新增：数据维护相关路由 ==========
    Route::prefix('maintenance')->middleware('role:admin')->group(function () {
        Route::get('/types', [\App\Http\Controllers\MaintenanceController::class, 'types']);
        Route::post('/scan', [\App\Http\Controllers\MaintenanceController::class, 'scan']);
        Route::post('/execute', [\App\Http\Controllers\MaintenanceController::class, 'execute']);
        Route::get('/history', [\App\Http\Controllers\MaintenanceController::class, 'history']);
        Route::post('/export', [\App\Http\Controllers\MaintenanceController::class, 'export']);
        Route::get('/export/{filename}', [\App\Http\Controllers\MaintenanceController::class, 'downloadExport']);
    });

    Route::prefix('system-updates')->middleware(['auth:sanctum', 'permission:system-updates.manage'])->group(function () {
        Route::get('/current', [SystemUpdateController::class, 'current']);
        Route::get('/check', [SystemUpdateController::class, 'check']);
        Route::get('/preflight', [SystemUpdateController::class, 'preflight']);
        Route::post('/install', [SystemUpdateController::class, 'install']);
        Route::get('/runs', [SystemUpdateController::class, 'index']);
        Route::get('/runs/{run}', [SystemUpdateController::class, 'show']);
        Route::post('/rollback', [SystemUpdateController::class, 'rollback']);
    });
});
