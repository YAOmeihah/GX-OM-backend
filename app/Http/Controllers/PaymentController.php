<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAllocationStrategy;
use App\Http\Requests\Payment\AllocatePaymentRequest;
use App\Http\Requests\Payment\ApplyDiscountRequest;
use App\Http\Requests\Payment\AutoAllocateRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AutoAllocationService;
use App\Services\DiscountValidationException;
use App\Services\PaymentCreationService;
use App\Services\PaymentDiscountService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group 还款管理
 *
 * 还款记录的创建、查询、分配和撤销等操作
 */
class PaymentController extends ApiController
{
    public function __construct(
        private readonly AutoAllocationService $allocations,
        private readonly PaymentCreationService $paymentCreation,
        private readonly PaymentDiscountService $discounts,
    ) {}

    /**
     * 获取还款列表
     *
     * 获取还款记录的分页列表，非管理员只能查看自己所属门店的还款。
     * 支持按门店、客户、搜索关键词、支付方式、分配状态、金额和日期范围筛选。
     *
     * @queryParam store_id integer 按门店ID筛选 Example: 1
     * @queryParam customer_id integer 按客户ID筛选 Example: 1
     * @queryParam search string 搜索还款单号、参考号、备注、客户姓名或手机号 Example: 张三
     * @queryParam payment_method string 按支付方式筛选，可选值：cash、bank_transfer、wechat、alipay、other Example: cash
     * @queryParam allocation_status string 分配状态：unallocated、allocated Example: unallocated
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     * @queryParam min_amount number 最小还款金额 Example: 100
     * @queryParam max_amount number 最大还款金额 Example: 1000
     * @queryParam sort_by string 排序字段：created_at、amount、allocated_amount、unallocated_amount Example: created_at
     * @queryParam sort_dir string 排序方向：asc、desc Example: desc
     * @queryParam per_page integer 每页显示数量，默认15 Example: 15
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "payment_number": "PAY-MAIN-20240101-ABC12",
     *         "store_id": 1,
     *         "customer_id": 1,
     *         "received_by": 1,
     *         "amount": "500.00",
     *         "allocated_amount": "500.00",
     *         "payment_method": "cash",
     *         "reference_number": null,
     *         "remarks": "现金还款",
     *         "created_at": "2024-01-05T00:00:00.000000Z",
     *         "updated_at": "2024-01-05T00:00:00.000000Z",
     *         "store": {
     *           "id": 1,
     *           "name": "总店"
     *         },
     *         "customer": {
     *           "id": 1,
     *           "name": "张三",
     *           "phone": "13800138001"
     *         },
     *         "received_by": {
     *           "id": 1,
     *           "name": "管理员"
     *         },
     *         "discounts": []
     *       }
     *     ],
     *     "first_page_url": "http://localhost/api/payments?page=1",
     *     "from": 1,
     *     "last_page": 5,
     *     "per_page": 15,
     *     "total": 75
     *   }
     * }
     * @response 403 scenario="无权限" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Payment::query();

        // 如果不是管理员，只能查看自己所属门店的还款
        if (! $this->isAdmin()) {
            $storeIds = $user->stores->pluck('id')->toArray();
            $query->whereIn('store_id', $storeIds);
        }

        // 按门店筛选
        if ($request->has('store_id')) {
            $storeId = $request->input('store_id');
            // 验证用户是否有权限查看该门店的还款
            if (! $this->isAdmin() && ! $this->belongsToStore($storeId)) {
                return $this->errorResponse('权限不足', 403);
            }
            $query->where('store_id', $storeId);
        }

        // 按客户筛选
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        // 按支付方式筛选
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('allocation_status')) {
            $allocationStatus = $request->input('allocation_status');
            if ($allocationStatus === 'unallocated') {
                $query->whereColumn('allocated_amount', '<', 'amount');
            } elseif ($allocationStatus === 'allocated') {
                $query->whereColumn('allocated_amount', '>=', 'amount');
            }
        }

        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->input('min_amount'));
        }

        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->input('max_amount'));
        }

        // 按日期范围筛选
        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('start_date'))->startOfDay());
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('end_date'))->endOfDay());
        }

        // 加载关联数据
        $query->with([
            'store:id,name',
            'customer:id,name,phone',
            'receivedBy:id,name',
            'discounts' => function ($query) {
                $query->select('id', 'payment_id', 'discount_amount', 'discount_type');
            },
            'attachments',
        ]);

        // 排序
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'amount', 'allocated_amount', 'unallocated_amount'];

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        if ($sortBy === 'unallocated_amount') {
            $query->orderByRaw('(amount - allocated_amount) '.$sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        // 分页
        $payments = $query->paginate($request->input('per_page', 15));

        return $this->successResponse($payments);
    }

    /**
     * 创建还款记录
     *
     * 创建新的还款记录。可以同时指定分配到哪些账单，也可以直接处理优惠减免。
     *
     * @bodyParam store_id integer required 门店ID Example: 1
     * @bodyParam customer_id integer required 客户ID Example: 1
     * @bodyParam amount number required 还款金额，最小0.01 Example: 500.00
     * @bodyParam payment_method string required 支付方式，可选值：cash、bank_transfer、wechat、alipay、other Example: cash
     * @bodyParam reference_number string 交易参考号/流水号，最大255字符 Example: TXN20240105001
     * @bodyParam remarks string 备注 Example: 现金还款
     * @bodyParam allocations array 还款分配列表（手动分配时使用）
     * @bodyParam allocations[].invoice_id integer required 账单ID Example: 1
     * @bodyParam allocations[].amount number required 分配金额，最小0.01 Example: 500.00
     * @bodyParam apply_discount boolean 是否应用优惠减免 Example: false
     * @bodyParam discount_data array 优惠减免数据（当apply_discount为true时使用）
     * @bodyParam discount_data[].invoice_id integer required 账单ID Example: 1
     * @bodyParam discount_data[].amount number required 减免金额 Example: 50.00
     * @bodyParam discount_data[].type string 减免类型：write_off(抹零)、discount(折扣)、promotion(促销) Example: write_off
     * @bodyParam discount_data[].reason string 减免原因，最大500字符 Example: 尾数抹零
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "payment_number": "PAY-MAIN-20240105-ABC12",
     *     "store_id": 1,
     *     "customer_id": 1,
     *     "received_by": 1,
     *     "amount": "500.00",
     *     "allocated_amount": "500.00",
     *     "payment_method": "cash",
     *     "reference_number": "TXN20240105001",
     *     "remarks": "现金还款",
     *     "created_at": "2024-01-05T00:00:00.000000Z",
     *     "updated_at": "2024-01-05T00:00:00.000000Z",
     *     "allocations": [
     *       {
     *         "id": 1,
     *         "payment_id": 1,
     *         "invoice_id": 1,
     *         "amount": "500.00",
     *         "invoice": {
     *           "id": 1,
     *           "invoice_number": "MAIN-20240101-ABC12"
     *         }
     *       }
     *     ],
     *     "customer": {
     *       "id": 1,
     *       "name": "张三"
     *     },
     *     "store": {
     *       "id": 1,
     *       "name": "总店"
     *     }
     *   },
     *   "message": "还款记录创建成功"
     * }
     * @response 403 scenario="无权限" {
     *   "success": false,
     *   "message": "您没有权限在此门店创建还款记录"
     * }
     * @response 422 scenario="客户无欠款" {
     *   "success": false,
     *   "message": "该客户没有未付清的账单"
     * }
     * @response 422 scenario="分配金额超限" {
     *   "success": false,
     *   "message": "分配总金额超过了还款金额"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function store(StorePaymentRequest $request)
    {
        $user = Auth::user();

        // 权限检查和验证已在 StorePaymentRequest 中完成
        $validated = $request->validated();

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
    }

    /**
     * 获取还款详情
     *
     * 获取指定还款的详细信息，包括门店、客户、收款人、分配记录和优惠减免记录。
     *
     * @urlParam id integer required 还款ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "payment_number": "PAY-MAIN-20240105-ABC12",
     *     "store_id": 1,
     *     "customer_id": 1,
     *     "received_by": 1,
     *     "amount": "500.00",
     *     "allocated_amount": "500.00",
     *     "payment_method": "cash",
     *     "reference_number": "TXN20240105001",
     *     "remarks": "现金还款",
     *     "store": {
     *       "id": 1,
     *       "name": "总店"
     *     },
     *     "customer": {
     *       "id": 1,
     *       "name": "张三",
     *       "phone": "13800138001"
     *     },
     *     "received_by": {
     *       "id": 1,
     *       "name": "管理员"
     *     },
     *     "allocations": [
     *       {
     *         "id": 1,
     *         "payment_id": 1,
     *         "invoice_id": 1,
     *         "amount": "500.00",
     *         "invoice": {
     *           "id": 1,
     *           "invoice_number": "MAIN-20240101-ABC12",
     *           "amount": "1000.00"
     *         },
     *         "allocated_by": {
     *           "id": 1,
     *           "name": "管理员"
     *         }
     *       }
     *     ],
     *     "discounts": []
     *   }
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function show($id)
    {
        $payment = Payment::with([
            'store:id,name',
            'customer:id,name,phone',
            'receivedBy:id,name',
            'allocations.invoice',
            'allocations.allocatedBy:id,name',
            'discounts.invoice',
            'discounts.approvedBy:id,name',
            'attachments',
        ])->findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('view', $payment);

        return $this->successResponse($payment);
    }

    /**
     * 分配还款到账单
     *
     * 将还款金额手动分配到指定账单。账单必须与还款属于同一客户和门店。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @bodyParam invoice_id integer required 账单ID Example: 1
     * @bodyParam amount number required 分配金额，最小0.01 Example: 500.00
     *
     * @response 200 scenario="分配成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "payment_id": 1,
     *     "invoice_id": 1,
     *     "amount": "500.00",
     *     "allocated_by": 1,
     *     "created_at": "2024-01-05T00:00:00.000000Z"
     *   },
     *   "message": "还款分配成功"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="客户/门店不匹配" {
     *   "success": false,
     *   "message": "账单与还款的客户或门店不匹配"
     * }
     * @response 422 scenario="超过账单剩余金额" {
     *   "success": false,
     *   "message": "分配金额超过了账单剩余未付金额"
     * }
     * @response 422 scenario="超过还款剩余金额" {
     *   "success": false,
     *   "message": "分配金额超过了还款剩余未分配金额"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function allocate(AllocatePaymentRequest $request, $id)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // 权限检查已在 AllocatePaymentRequest 中完成
        $validated = $request->validated();

        $invoice = Invoice::findOrFail($validated['invoice_id']);

        // 验证账单是否属于同一客户和门店
        if ($invoice->customer_id != $payment->customer_id || $invoice->store_id != $payment->store_id) {
            return $this->errorResponse('账单与还款的客户或门店不匹配', 422);
        }

        // 验证分配金额不超过账单剩余未付金额
        $invoice->loadMissing('discounts');
        $remainingAmount = $invoice->actual_remaining_amount;
        if ($validated['amount'] > $remainingAmount) {
            return $this->errorResponse('分配金额超过了账单剩余未付金额', 422);
        }

        // 验证分配金额不超过还款剩余未分配金额
        $unallocatedAmount = $payment->amount - $payment->allocated_amount;
        if ($validated['amount'] > $unallocatedAmount) {
            return $this->errorResponse('分配金额超过了还款剩余未分配金额', 422);
        }

        try {
            $allocation = $payment->allocateToInvoice($invoice, $validated['amount'], $user->id);

            // 重新加载还款数据以获取最新状态
            $payment->refresh();
            $payment->load(['customer', 'store', 'allocations.invoice', 'discounts.invoice', 'receivedBy:id,name', 'attachments']);

            // 确保 received_by 返回的是对象
            if ($payment->relationLoaded('receivedBy')) {
                $payment->setAttribute('received_by', $payment->receivedBy);
            }

            return $this->successResponse($payment, '还款分配成功');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            \Log::error('还款分配失败', [
                'user_id' => Auth::id(),
                'payment_id' => $payment->id ?? null,
                'invoice_id' => $validated['invoice_id'] ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('还款操作失败', 500);
        }
    }

    /**
     * 批量分配还款到多个账单
     *
     * 将还款金额一次性分配到多个账单。所有账单必须与还款属于同一客户和门店。
     * 操作在事务中执行，确保原子性（全部成功或全部失败）。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @bodyParam allocations array required 分配列表
     * @bodyParam allocations[].invoice_id integer required 账单ID Example: 1
     * @bodyParam allocations[].amount number required 分配金额，最小0.01 Example: 500.00
     *
     * @response 200 scenario="分配成功" {
     *   "success": true,
     *   "data": {...payment object...},
     *   "message": "成功分配 3 笔账单"
     * }
     * @response 422 scenario="分配总额超过剩余金额" {
     *   "success": false,
     *   "message": "分配总金额 (1500.00) 超过了还款剩余未分配金额 (1000.00)"
     * }
     * @response 422 scenario="账单不匹配" {
     *   "success": false,
     *   "message": "账单 INV-001 与还款的客户或门店不匹配"
     * }
     */
    public function batchAllocate(\App\Http\Requests\Payment\BatchAllocatePaymentRequest $request, $id)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($id);
        $validated = $request->validated();

        $allocations = $validated['allocations'];

        // ========== 边界场景验证 ==========

        // 1. 检查是否有重复的账单ID
        $invoiceIds = array_column($allocations, 'invoice_id');
        $uniqueIds = array_unique($invoiceIds);
        if (count($invoiceIds) !== count($uniqueIds)) {
            return $this->errorResponse('分配列表中存在重复的账单', 422);
        }

        // 2. 计算分配总额，检查是否超过剩余金额
        $totalAllocateAmount = array_sum(array_column($allocations, 'amount'));
        $unallocatedAmount = $payment->amount - $payment->allocated_amount;

        if (\App\Helpers\MoneyHelper::isGreaterThan($totalAllocateAmount, $unallocatedAmount)) {
            return $this->errorResponse(
                sprintf('分配总金额 (%.2f) 超过了还款剩余未分配金额 (%.2f)', $totalAllocateAmount, $unallocatedAmount),
                422
            );
        }

        // 3. 预验证所有账单
        $invoices = [];
        foreach ($allocations as $alloc) {
            $invoice = Invoice::find($alloc['invoice_id']);

            if (! $invoice) {
                return $this->errorResponse("账单 ID {$alloc['invoice_id']} 不存在", 422);
            }

            // 验证账单是否属于同一客户和门店
            if ($invoice->customer_id != $payment->customer_id || $invoice->store_id != $payment->store_id) {
                return $this->errorResponse(
                    "账单 {$invoice->invoice_number} 与还款的客户或门店不匹配",
                    422
                );
            }

            // 验证分配金额不超过账单剩余未付金额
            $invoice->loadMissing('discounts');
            $invoiceRemaining = $invoice->actual_remaining_amount;
            if (\App\Helpers\MoneyHelper::isGreaterThan($alloc['amount'], $invoiceRemaining)) {
                return $this->errorResponse(
                    sprintf(
                        '账单 %s 的分配金额 (%.2f) 超过了剩余未付金额 (%.2f)',
                        $invoice->invoice_number,
                        $alloc['amount'],
                        $invoiceRemaining
                    ),
                    422
                );
            }

            $invoices[$alloc['invoice_id']] = $invoice;
        }

        // ========== 执行批量分配 ==========
        DB::beginTransaction();

        try {
            $successCount = 0;

            foreach ($allocations as $alloc) {
                $invoice = $invoices[$alloc['invoice_id']];
                $payment->allocateToInvoice($invoice, $alloc['amount'], $user->id);
                $successCount++;
            }

            DB::commit();

            // 重新加载还款数据
            $payment->refresh();
            $payment->load(['customer', 'store', 'allocations.invoice', 'discounts.invoice', 'receivedBy:id,name', 'attachments']);

            if ($payment->relationLoaded('receivedBy')) {
                $payment->setAttribute('received_by', $payment->receivedBy);
            }

            return $this->successResponse($payment, "成功分配 {$successCount} 笔账单");

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->errorResponse('批量分配失败：'.$e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('批量分配失败', [
                'user_id' => Auth::id(),
                'payment_id' => $payment->id ?? null,
                'allocations' => $allocations ?? [],
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('批量分配失败', 500);
        }
    }

    /**
     * 删除还款记录
     *
     * 删除指定还款记录。需要管理员或店长权限。删除时会自动撤销该还款的所有分配记录。
     *
     * @urlParam id integer required 还款ID Example: 1
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "还款记录删除成功"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('delete', $payment);

        $user = Auth::user();

        // 使用事务确保数据一致性
        DB::transaction(function () use ($payment, $user) {
            // 如果有分配记录，先撤销所有分配（会更新相关账单状态）
            if ($payment->allocated_amount > 0 || $payment->allocations()->count() > 0) {
                $payment->revokeAllAllocations($user->id);
            }

            // 删除关联的附件（会触发 Attachment 模型的 deleting 事件清理存储文件）
            $payment->attachments()->each(function ($attachment) {
                $attachment->delete();
            });

            // 删除关联的优惠减免记录（需要先更新相关账单状态）
            foreach ($payment->discounts as $discount) {
                // 获取关联的账单并更新其状态
                $invoice = $discount->invoice;
                $discount->delete();
                if ($invoice) {
                    $invoice->updateStatus();
                }
            }

            // 最后删除还款记录本身
            $payment->delete();
        });

        return $this->successResponse(null, '还款记录删除成功');
    }

    /**
     * 获取自动分配建议
     *
     * 根据指定策略获取还款的自动分配建议。
     * 需要管理员或店长权限。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @queryParam strategy string 分配策略，可选值：oldest_first(最早优先)、due_date_first(到期日优先)、smallest_first(最小金额优先)、largest_first(最大金额优先)、overdue_first(逾期优先)，默认oldest_first Example: oldest_first
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "500.00",
     *       "customer": {"id": 1, "name": "张三"},
     *       "store": {"id": 1, "name": "总店"}
     *     },
     *     "suggestion": {
     *       "allocations": [
     *         {
     *           "invoice_id": 1,
     *           "invoice_number": "MAIN-20240101-ABC12",
     *           "suggested_amount": 500.00,
     *           "remaining_after_allocation": 500.00
     *         }
     *       ],
     *       "total_allocation": 500.00,
     *       "remaining_unallocated": 0.00
     *     },
     *     "excess_info": {
     *       "is_excess": false,
     *       "excess_amount": 0.00
     *     },
     *     "available_strategies": [
     *       {"value": "oldest_first", "description": "按账单日期从早到晚分配"},
     *       {"value": "due_date_first", "description": "按到期日期从早到晚分配"},
     *       {"value": "smallest_first", "description": "从最小金额账单开始分配"},
     *       {"value": "largest_first", "description": "从最大金额账单开始分配"},
     *       {"value": "overdue_first", "description": "优先分配逾期账单"}
     *     ]
     *   },
     *   "message": "分配建议获取成功"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function getAllocationSuggestion(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('autoAllocate', $payment);

        $request->validate([
            'include_discount' => [
                'nullable',
                Rule::notIn([true, 1, '1', 'true', 'on', 'yes']),
            ],
        ], [
            'include_discount.not_in' => '自动分配建议不支持自动减免，请使用独立减免或一键清账流程',
        ]);

        $strategy = PaymentAllocationStrategy::fromString(
            $request->input('strategy', 'oldest_first')
        );

        $allocationService = $this->allocations;

        // 自动分配只建议还款金额如何分配，不隐式创建或建议减免。
        $suggestion = $allocationService->getAllocationSuggestion($payment, $strategy);

        // 检测超额还款
        $excessInfo = $allocationService->detectExcessPayment($payment);

        return $this->successResponse([
            'payment' => $payment->load(['customer', 'store']),
            'suggestion' => $suggestion,
            'excess_info' => $excessInfo,
            'available_strategies' => collect(PaymentAllocationStrategy::getAvailableStrategies())
                ->map(fn ($strategy) => [
                    'value' => $strategy->value,
                    'description' => $strategy->getDescription(),
                ]),
        ], '分配建议获取成功');
    }

    /**
     * 执行自动分配
     *
     * 根据指定策略自动将还款分配到客户的未付账单。
     * 需要管理员或店长权限。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @bodyParam strategy string 分配策略，可选值：oldest_first、due_date_first、smallest_first、largest_first、overdue_first，默认oldest_first Example: oldest_first
     * @bodyParam confirm_excess boolean 确认超额还款（当检测到超额时需要设为true才能继续） Example: false
     *
     * @response 200 scenario="分配成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "500.00",
     *       "allocated_amount": "500.00",
     *       "allocations": [],
     *       "discounts": []
     *     },
     *     "allocations": [
     *       {
     *         "invoice_id": 1,
     *         "amount": 500.00
     *       }
     *     ],
     *     "discounts": [],
     *     "strategy_used": "oldest_first",
     *     "excess_info": {
     *       "is_excess": false
     *     },
     *     "message": "自动分配完成"
     *   },
     *   "message": "自动分配完成"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="超额未确认" {
     *   "success": false,
     *   "message": "检测到超额还款，请确认是否继续",
     *   "data": {
     *     "excess_info": {"is_excess": true, "excess_amount": 100.00},
     *     "requires_confirmation": true
     *   }
     * }
     * @response 422 scenario="无可分配账单" {
     *   "success": false,
     *   "message": "没有找到可分配的账单"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function autoAllocate(AutoAllocateRequest $request, $id)
    {
        $payment = Payment::findOrFail($id);

        // 权限检查和验证已在 AutoAllocateRequest 中完成
        $validated = $request->validated();

        $strategy = PaymentAllocationStrategy::fromString(
            $validated['strategy'] ?? 'oldest_first'
        );

        $allocationService = $this->allocations;

        // 检查超额还款
        $excessInfo = $allocationService->detectExcessPayment($payment);
        if ($excessInfo['is_excess'] && ! ($validated['confirm_excess'] ?? false)) {
            return $this->errorResponse('检测到超额还款，请确认是否继续', 422, [
                'excess_info' => $excessInfo,
                'requires_confirmation' => true,
            ]);
        }

        try {
            $allocations = $allocationService->autoAllocate($payment, $strategy);

            if (empty($allocations)) {
                return $this->errorResponse('没有找到可分配的账单', 422);
            }

            // 重新加载还款数据以获取最新状态
            $payment->refresh();
            $payment->load(['customer', 'store', 'allocations.invoice', 'receivedBy:id,name', 'attachments']);

            // 确保 received_by 返回的是对象
            if ($payment->relationLoaded('receivedBy')) {
                $payment->setAttribute('received_by', $payment->receivedBy);
            }

            return $this->successResponse($payment, '自动分配完成');

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('自动分配失败：'.$e->getMessage(), 422);
        } catch (\Exception $e) {
            \Log::error('自动分配失败', [
                'user_id' => Auth::id(),
                'payment_id' => $payment->id ?? null,
                'strategy' => $validated['strategy'] ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('自动分配失败', 500);
        }
    }

    /**
     * 批量自动分配
     *
     * 批量对多笔还款执行自动分配。需要管理员或店长权限。
     *
     * @bodyParam payment_ids array required 还款ID列表 Example: [1, 2, 3]
     * @bodyParam payment_ids.* integer required 还款ID Example: 1
     * @bodyParam strategy string 分配策略，默认oldest_first Example: oldest_first
     * @bodyParam store_id integer 限定门店ID（可选） Example: 1
     *
     * @response 200 scenario="批量分配成功" {
     *   "success": true,
     *   "data": {
     *     "results": [
     *       {
     *         "payment_id": 1,
     *         "success": true,
     *         "allocations": [{"invoice_id": 1, "amount": 500.00}],
     *         "message": "分配成功"
     *       },
     *       {
     *         "payment_id": 2,
     *         "success": false,
     *         "message": "没有可分配的账单"
     *       }
     *     ],
     *     "summary": {
     *       "total_payments": 2,
     *       "successful_allocations": 1,
     *       "failed_allocations": 1,
     *       "strategy_used": "oldest_first"
     *     }
     *   },
     *   "message": "批量自动分配完成，成功处理 1/2 笔还款"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或对应店长权限"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "payment_ids": ["还款ID列表不能为空"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function batchAutoAllocate(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'payment_ids' => 'required|array|min:1',
            'payment_ids.*' => 'required|integer|exists:payments,id',
            'strategy' => 'nullable|string|in:oldest_first,due_date_first,smallest_first,largest_first,overdue_first',
            'store_id' => 'nullable|integer|exists:stores,id',
        ]);

        $requestedStoreId = $validated['store_id'] ?? null;

        // 批量自动分配需要管理员或对应门店店长权限。
        $payments = Payment::whereIn('id', $validated['payment_ids'])->get();
        foreach ($payments as $payment) {
            if (! $this->isAdmin() && ! $this->isManagerOfStore($payment->store_id)) {
                return $this->errorResponse("还款 #{$payment->id} 不属于你可管理的门店", 403);
            }
            if ($requestedStoreId && $payment->store_id != $requestedStoreId) {
                return $this->errorResponse("还款 #{$payment->id} 不属于指定门店", 422);
            }
        }

        $strategy = PaymentAllocationStrategy::fromString(
            $validated['strategy'] ?? 'oldest_first'
        );

        $allocationService = $this->allocations;

        try {
            $results = $allocationService->batchAutoAllocate($validated['payment_ids'], $strategy);

            $successCount = count(array_filter($results, fn ($r) => $r['success']));
            $totalCount = count($results);

            return $this->successResponse([
                'results' => $results,
                'summary' => [
                    'total_payments' => $totalCount,
                    'successful_allocations' => $successCount,
                    'failed_allocations' => $totalCount - $successCount,
                    'strategy_used' => $strategy->value,
                ],
            ], "批量自动分配完成，成功处理 {$successCount}/{$totalCount} 笔还款");

        } catch (\Exception $e) {
            \Log::error('批量自动分配失败', [
                'user_id' => Auth::id(),
                'payment_ids' => $validated['payment_ids'] ?? [],
                'store_id' => $validated['store_id'] ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('批量自动分配失败', 500);
        }
    }

    /**
     * 检测还款差额
     *
     * 检测还款金额与客户欠款之间的差额，用于判断是否需要优惠减免。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @response 200 scenario="检测成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "980.00",
     *       "customer": {"id": 1, "name": "张三"},
     *       "store": {"id": 1, "name": "总店"}
     *     },
     *     "gap_info": {
     *       "total_debt": 1000.00,
     *       "payment_amount": 980.00,
     *       "gap_amount": 20.00,
     *       "gap_type": "underpayment",
     *       "suggested_action": "apply_discount"
     *     },
     *     "can_approve_discount": true
     *   },
     *   "message": "差额检测完成"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function detectGap($id)
    {
        $payment = Payment::findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('detectGap', $payment);

        $discountService = $this->discounts;
        $gapInfo = $discountService->detectPaymentGap($payment);

        return $this->successResponse([
            'payment' => $payment->load(['customer', 'store']),
            'gap_info' => $gapInfo,
            'can_approve_discount' => $discountService->canApproveDiscount(Auth::id(), $payment->store_id),
        ], '差额检测完成');
    }

    /**
     * 应用优惠减免
     *
     * 为还款应用优惠减免，可以抹零、折扣或促销。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @bodyParam discount_data array required 优惠减免数据列表
     * @bodyParam discount_data[].invoice_id integer required 账单ID Example: 1
     * @bodyParam discount_data[].amount number required 减免金额 Example: 20.00
     * @bodyParam discount_data[].type string 减免类型：write_off(抹零)、discount(折扣)、promotion(促销) Example: write_off
     * @bodyParam discount_data[].reason string 减免原因 Example: 尾数抹零，客户整数付款
     *
     * @response 200 scenario="处理成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "980.00",
     *       "allocated_amount": "980.00",
     *       "allocations": [],
     *       "discounts": [
     *         {
     *           "id": 1,
     *           "payment_id": 1,
     *           "invoice_id": 1,
     *           "discount_amount": "20.00",
     *           "discount_type": "write_off",
     *           "reason": "尾数抹零",
     *           "invoice": {"id": 1, "invoice_number": "MAIN-20240101-ABC12"}
     *         }
     *       ]
     *     },
     *     "result": {
     *       "total_discount": 20.00,
     *       "invoices_affected": 1
     *     }
     *   },
     *   "message": "优惠减免处理成功"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 403 scenario="无减免权限" {
     *   "success": false,
     *   "message": "权限验证失败：您没有权限进行优惠减免操作"
     * }
     * @response 422 scenario="处理失败" {
     *   "success": false,
     *   "message": "优惠减免处理失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function applyDiscount(ApplyDiscountRequest $request, $id)
    {
        $payment = Payment::findOrFail($id);

        // 权限检查和验证已在 ApplyDiscountRequest 中完成
        $validated = $request->validated();

        $discountService = $this->discounts;

        try {
            $discountService->validateDiscountRequest($payment, $validated['discount_data'], Auth::id(), 'apply_discount');

            // 记录操作日志
            $discountService->logDiscountOperation($payment, $validated['discount_data'], Auth::id(), 'create');

            $result = $discountService->processDiscountScenario(
                $payment,
                $validated['discount_data'],
                Auth::id()
            );

            // 重新加载还款数据
            $payment->refresh();
            $payment->load(['allocations.invoice', 'discounts.invoice', 'customer', 'store']);

            return $this->successResponse([
                'payment' => $payment,
                'result' => $result,
            ], '优惠减免处理成功');

        } catch (\App\Services\DiscountValidationException $e) {
            return $this->errorResponse($e->getMessage(), $e->statusCode());
        } catch (\Exception $e) {
            // 记录错误日志
            $discountService->logDiscountOperation($payment, $validated['discount_data'], Auth::id(), 'failed');

            return $this->errorResponse('优惠减免处理失败：'.$e->getMessage(), 422);
        }
    }

    /**
     * 获取优惠减免统计
     *
     * 获取优惠减免的统计数据，包括按类型分组的减免金额和次数。
     *
     * @queryParam store_id integer 门店ID，不传则返回用户所属门店的统计 Example: 1
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "total_count": 50,
     *     "total_amount": 5000.00,
     *     "by_type": {
     *       "write_off": {
     *         "count": 30,
     *         "amount": 3000.00
     *       },
     *       "discount": {
     *         "count": 15,
     *         "amount": 1500.00
     *       },
     *       "promotion": {
     *         "count": 5,
     *         "amount": 500.00
     *       }
     *     },
     *     "by_month": [
     *       {
     *         "month": "2024-01",
     *         "count": 10,
     *         "amount": 1000.00
     *       }
     *     ]
     *   },
     *   "message": "优惠减免统计获取成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 403 scenario="无门店关联" {
     *   "success": false,
     *   "message": "您没有关联任何门店"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function getDiscountStatistics(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $storeId = $validated['store_id'] ?? null;

        // 权限检查
        if ($storeId && ! $this->isAdmin() && ! $this->belongsToStore($storeId)) {
            return $this->errorResponse('权限不足', 403);
        }

        // 如果不是管理员且没有指定门店，则获取用户所属门店的统计
        if (! $this->isAdmin() && ! $storeId) {
            $userStores = Auth::user()->stores->pluck('id')->toArray();
            if (empty($userStores)) {
                return $this->errorResponse('您没有关联任何门店', 403);
            }
            $storeId = $userStores[0]; // 默认使用第一个门店
        }

        $dateRange = null;
        if (! empty($validated['start_date']) && ! empty($validated['end_date'])) {
            $dateRange = [$validated['start_date'], $validated['end_date']];
        }

        $discountService = $this->discounts;
        $statistics = $discountService->getDiscountStatistics($storeId, $dateRange);

        return $this->successResponse($statistics, '优惠减免统计获取成功');
    }

    /**
     * 撤销单个分配记录
     *
     * 撤销指定的还款分配记录，将分配金额退还给还款和账单。
     * 需要管理员或店长权限。
     *
     * @urlParam payment integer required 还款ID Example: 1
     * @urlParam allocation integer required 分配记录ID Example: 1
     *
     * @response 200 scenario="撤销成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "500.00",
     *       "allocated_amount": "0.00",
     *       "allocations": []
     *     },
     *     "message": "分配撤销成功"
     *   },
     *   "message": "分配撤销成功"
     * }
     * @response 404 scenario="还款或分配记录不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="撤销失败" {
     *   "success": false,
     *   "message": "分配撤销失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function revokeAllocation(Request $request, $paymentId, $allocationId)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($paymentId);

        // 使用 Policy 进行权限检查
        $this->authorize('revokeAllocation', $payment);

        $allocation = \App\Models\PaymentAllocation::where('payment_id', $paymentId)
            ->where('id', $allocationId)
            ->firstOrFail();

        try {
            $payment->revokeAllocation($allocation, $user->id);

            // 重新加载还款数据
            $payment->refresh();
            $payment->load(['allocations.invoice', 'customer', 'store']);

            return $this->successResponse([
                'payment' => $payment,
                'message' => '分配撤销成功',
            ], '分配撤销成功');

        } catch (\Exception $e) {
            return $this->errorResponse('分配撤销失败：'.$e->getMessage(), 422);
        }
    }

    /**
     * 撤销所有分配记录
     *
     * 撤销指定还款的所有分配记录。需要管理员或店长权限。
     *
     * @urlParam payment integer required 还款ID Example: 1
     *
     * @response 200 scenario="撤销成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 1,
     *       "payment_number": "PAY-MAIN-20240105-ABC12",
     *       "amount": "500.00",
     *       "allocated_amount": "0.00"
     *     },
     *     "revoked_count": 3,
     *     "message": "成功撤销 3 条分配记录"
     *   },
     *   "message": "成功撤销 3 条分配记录"
     * }
     * @response 404 scenario="还款不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="无分配记录" {
     *   "success": false,
     *   "message": "该还款没有分配记录"
     * }
     * @response 422 scenario="撤销失败" {
     *   "success": false,
     *   "message": "分配撤销失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function revokeAllAllocations(Request $request, $id)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('revokeAllocation', $payment);

        // 检查是否有分配记录
        if ($payment->allocations()->count() === 0) {
            return $this->errorResponse('该还款没有分配记录', 422);
        }

        try {
            $count = $payment->revokeAllAllocations($user->id);

            // 重新加载还款数据
            $payment->refresh();
            $payment->load(['customer', 'store', 'allocations', 'attachments']);

            return $this->successResponse(
                $payment,
                "成功撤销 {$count} 条分配记录"
            );

        } catch (\Exception $e) {
            return $this->errorResponse('分配撤销失败：'.$e->getMessage(), 422);
        }
    }
}
