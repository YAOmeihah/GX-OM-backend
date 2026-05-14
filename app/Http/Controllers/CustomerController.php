<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 客户管理
 *
 * 客户信息的创建、查询、更新和欠款统计
 */
class CustomerController extends ApiController
{
    /**
     * 获取客户列表
     *
     * 获取系统中所有客户的分页列表，支持按姓名、电话、身份证号搜索。
     *
     * @queryParam search string 搜索关键词，可搜索姓名、电话、身份证号 Example: 张三
     * @queryParam per_page integer 每页显示数量，默认15 Example: 15
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "name": "张三",
     *         "phone": "13800138001",
     *         "email": "zhangsan@example.com",
     *         "address": "北京市朝阳区xxx路123号",
     *         "id_card": "110101199001011234",
     *         "remarks": "VIP客户",
     *         "created_at": "2024-01-01T00:00:00.000000Z",
     *         "updated_at": "2024-01-01T00:00:00.000000Z"
     *       }
     *     ],
     *     "first_page_url": "http://localhost/api/customers?page=1",
     *     "from": 1,
     *     "last_page": 5,
     *     "last_page_url": "http://localhost/api/customers?page=5",
     *     "next_page_url": "http://localhost/api/customers?page=2",
     *     "path": "http://localhost/api/customers",
     *     "per_page": 15,
     *     "prev_page_url": null,
     *     "to": 15,
     *     "total": 75
     *   }
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
        $query = Customer::query();

        // 获取门店ID用于上下文筛选
        $storeId = $request->input('store_id');
        // 确保用户只能查看自己有权限的门店
        $allowedStoreIds = $this->getUserStoreIds();
        $targetStoreIds = $storeId ? [(int) $storeId] : $allowedStoreIds;

        // 如果指定了 store_id，验证权限
        if ($storeId && ! in_array($storeId, $allowedStoreIds)) {
            $storeId = null;
            // 如果权限验证失败，回退到查看所有有权限的门店
            $targetStoreIds = $allowedStoreIds;
        }

        // 门店隔离：只返回属于目标门店的客户
        $query->whereIn('customers.store_id', $targetStoreIds);

        // 搜索条件
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('id_card', 'like', "%{$search}%");
            });
        }

        // ====== 核心优化 (Performance & Logic) ======
        // 废除旧版的动态实时子查询(N+1 性能死区)
        // 改用通过 Observer / Service 维护的物化缓存表 customer_store_stats 进行左连接

        $query->select('customers.*');

        if ($storeId) {
            // 如果明确指定了门店，我们左连结该门店的统计数据
            $query->leftJoin('customer_store_stats', function ($join) use ($storeId) {
                $join->on('customers.id', '=', 'customer_store_stats.customer_id')
                    ->where('customer_store_stats.store_id', '=', $storeId);
            });
            // 把统计表的字段拿出来，并赋予 coalesce 默认值防 null
            $query->addSelect([
                DB::raw('COALESCE(customer_store_stats.total_debt, 0) as total_debt'),
                'customer_store_stats.last_transaction_at',
            ]);
        } else {
            // 如果是看所有门店汇总情况(管理员视角)
            // 分组聚合查询所有店的欠款总和以及最高交易时间
            // 因为客户表客户数通常不算极大，这里的聚合仍可接受。为了极致也可在后台计算总缓存。
            $query->leftJoin('customer_store_stats', 'customers.id', '=', 'customer_store_stats.customer_id')
                ->groupBy('customers.id')
                ->addSelect([
                    DB::raw('COALESCE(SUM(customer_store_stats.total_debt), 0) as total_debt'),
                    DB::raw('MAX(customer_store_stats.last_transaction_at) as last_transaction_at'),
                ]);
        }

        // ====== 排序策略 ======
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'desc');
        $validSortDirs = ['asc', 'desc'];
        $direction = in_array(strtolower($sortDir), $validSortDirs) ? strtolower($sortDir) : 'desc';

        if ($sortBy) {
            // 允许的排序字段 (注意：total_debt 是上方 addSelect 添加的计算别名，可以支持排序)
            $validSortFields = ['name', 'total_debt', 'created_at', 'last_transaction_at'];
            if (in_array(strtolower($sortBy), $validSortFields)) {
                $query->orderBy(strtolower($sortBy), $direction);
            }
        } else {
            // 没有传递排序参数时的默认回退逻辑
            if ($storeId) {
                // 特定门店上下文：智能排序 - 近期交易优先、欠款其次
                $query->orderByDesc('last_transaction_at');
                $query->orderByDesc('total_debt');
                $query->latest(); // 兜底：创建时间优先
            } else {
                $query->latest();
            }
        }

        // 终极兜底排序
        $query->orderBy('id', 'desc');

        // 分页
        $customers = $query->paginate($request->input('per_page', 15));

        \Log::debug('CustomerController.index()', [
            'request_store_id' => $storeId,
            'filtered_store_ids' => $targetStoreIds,
        ]);

        // 移除旧的 N+1 遍历计算逻辑
        // $customers->getCollection()->transform(...) Removed

        return $this->successResponse($customers);
    }

    /**
     * 创建客户
     *
     * 创建新的客户记录。
     *
     * @bodyParam name string required 客户姓名，最大255字符 Example: 张三
     * @bodyParam phone string 客户手机号，最大20字符 Example: 13800138001
     * @bodyParam email string 客户邮箱 Example: zhangsan@example.com
     * @bodyParam address string 客户地址，最大255字符 Example: 北京市朝阳区xxx路123号
     * @bodyParam id_card string 身份证号，最大18字符 Example: 110101199001011234
     * @bodyParam remarks string 备注信息 Example: VIP客户
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "张三",
     *     "phone": "13800138001",
     *     "email": "zhangsan@example.com",
     *     "address": "北京市朝阳区xxx路123号",
     *     "id_card": "110101199001011234",
     *     "remarks": "VIP客户",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   },
     *   "message": "客户创建成功"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "name": ["姓名不能为空"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function store(StoreCustomerRequest $request)
    {
        // 验证已在 StoreCustomerRequest 中完成
        $validated = $request->validated();

        $customer = Customer::create($validated);

        return $this->successResponse($customer, '客户创建成功', 201);
    }

    /**
     * 获取客户详情
     *
     * 获取指定客户的详细信息，包括该客户在当前用户有权限访问的门店中的账单和还款记录。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "张三",
     *     "phone": "13800138001",
     *     "email": "zhangsan@example.com",
     *     "address": "北京市朝阳区xxx路123号",
     *     "id_card": "110101199001011234",
     *     "remarks": "VIP客户",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z",
     *     "invoices": [
     *       {
     *         "id": 1,
     *         "invoice_number": "MAIN-20240101-ABC12",
     *         "amount": "1000.00",
     *         "paid_amount": "500.00",
     *         "status": "partially_paid"
     *       }
     *     ],
     *     "payments": [
     *       {
     *         "id": 1,
     *         "payment_number": "PAY-MAIN-20240101-XYZ99",
     *         "amount": "500.00",
     *         "payment_method": "cash"
     *       }
     *     ]
     *   }
     * }
     * @response 404 scenario="客户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="无门店权限" {
     *   "success": false,
     *   "message": "您没有权限访问任何门店的数据"
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
        $customer = Customer::findOrFail($id);

        // 获取用户有权限访问的门店ID列表
        $storeIds = $this->getUserStoreIds();

        if (empty($storeIds)) {
            return $this->errorResponse('您没有权限访问任何门店的数据', 403);
        }

        // 验证用户是否有权限访问该客户所属门店
        if (! in_array($customer->store_id, $storeIds)) {
            return $this->errorResponse('您没有权限访问该客户', 403);
        }

        // 检查是否指定了特定门店
        $requestedStoreId = request()->input('store_id');
        if ($requestedStoreId) {
            if (! in_array($requestedStoreId, $storeIds)) {
                return $this->errorResponse('您没有权限访问该门店的数据', 403);
            }
            // 只使用指定的门店
            $filterStoreIds = [$requestedStoreId];
        } else {
            // 如果未指定，使用用户有权限的所有门店
            $filterStoreIds = $storeIds;
        }

        // 只加载过滤后门店的账单和还款记录
        $customer->load([
            'invoices' => function ($query) use ($filterStoreIds) {
                $query->whereIn('store_id', $filterStoreIds)
                    ->orderBy('created_at', 'desc');
            },
            'payments' => function ($query) use ($filterStoreIds) {
                $query->whereIn('store_id', $filterStoreIds)
                    ->orderBy('created_at', 'desc');
            },
        ]);

        // 设置门店过滤后的欠款金额
        $customer->setAttribute('total_debt', $customer->getTotalDebtForStores($filterStoreIds));

        // 设置最近交易时间 (与列表接口保持一致，基于 Invoices)
        $lastTransactionAt = \App\Models\Invoice::where('customer_id', $customer->id)
            ->whereIn('store_id', $filterStoreIds)
            ->max('created_at');

        $customer->setAttribute('last_transaction_at', $lastTransactionAt);

        return $this->successResponse($customer);
    }

    /**
     * 更新客户信息
     *
     * 更新指定客户的信息。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @bodyParam name string 客户姓名，最大255字符 Example: 李四
     * @bodyParam phone string 客户手机号，最大20字符 Example: 13800138002
     * @bodyParam email string 客户邮箱 Example: lisi@example.com
     * @bodyParam address string 客户地址，最大255字符 Example: 上海市浦东新区xxx路456号
     * @bodyParam id_card string 身份证号，最大18字符 Example: 310101199002021234
     * @bodyParam remarks string 备注信息 Example: 普通客户
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "李四",
     *     "phone": "13800138002",
     *     "email": "lisi@example.com",
     *     "address": "上海市浦东新区xxx路456号",
     *     "id_card": "310101199002021234",
     *     "remarks": "普通客户",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-02T00:00:00.000000Z"
     *   },
     *   "message": "客户更新成功"
     * }
     * @response 404 scenario="客户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "email": ["邮箱格式不正确"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function update(UpdateCustomerRequest $request, $id)
    {
        $customer = Customer::findOrFail($id);

        // 验证用户是否有权限访问该客户所属门店
        $allowedStoreIds = $this->getUserStoreIds();
        if (! in_array($customer->store_id, $allowedStoreIds)) {
            return $this->errorResponse('您没有权限修改该客户', 403);
        }

        // 验证已在 UpdateCustomerRequest 中完成
        $validated = $request->validated();

        $customer->update($validated);

        return $this->successResponse($customer, '客户更新成功');
    }

    /**
     * 删除客户
     *
     * 删除指定客户。只有管理员可以执行此操作，且客户不能有关联的账单或还款记录。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "客户删除成功"
     * }
     * @response 404 scenario="客户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "只有管理员可以删除客户"
     * }
     * @response 422 scenario="有关联记录" {
     *   "success": false,
     *   "message": "该客户有关联的账单或还款记录，无法删除"
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
        $customer = Customer::findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('delete', $customer);

        // 检查客户是否有关联的账单或还款
        if ($customer->invoices()->exists() || $customer->payments()->exists()) {
            return $this->errorResponse('该客户有关联的账单或还款记录，无法删除', 422);
        }

        $customer->delete();

        return $this->successResponse(null, '客户删除成功');
    }

    /**
     * 获取客户欠款汇总
     *
     * 获取指定客户的欠款详情，包括传统欠款、实际欠款（扣除优惠减免后）、
     * 优惠减免统计以及未付账单列表。
     *
     * @urlParam customer integer required 客户ID Example: 1
     *
     * @queryParam store_id integer 指定门店ID，不传则返回所有有权限门店的汇总 Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "customer": {
     *       "id": 1,
     *       "name": "张三",
     *       "phone": "13800138001"
     *     },
     *     "traditional_debt": 5000.00,
     *     "actual_debt": 4800.00,
     *     "discount_summary": {
     *       "total_count": 2,
     *       "total_amount": 200.00,
     *       "by_type": {
     *         "write_off": {
     *           "count": 1,
     *           "amount": 100.00
     *         },
     *         "discount": {
     *           "count": 1,
     *           "amount": 100.00
     *         }
     *       }
     *     },
     *     "store_debt_info": {
     *       "total_invoices": 10,
     *       "unpaid_invoices": 3,
     *       "total_amount": 10000.00,
     *       "paid_amount": 5000.00,
     *       "discount_amount": 200.00,
     *       "traditional_debt": 5000.00,
     *       "actual_debt": 4800.00,
     *       "discount_rate": 2.00,
     *       "store_count": 2
     *     },
     *     "accessible_stores": [1, 2],
     *     "unpaid_invoices": [
     *       {
     *         "id": 1,
     *         "invoice_number": "MAIN-20240101-ABC12",
     *         "store_id": 1,
     *         "amount": "2000.00",
     *         "paid_amount": "500.00",
     *         "discount_amount": "100.00",
     *         "actual_remaining": "1400.00",
     *         "status": "partially_paid",
     *         "due_date": "2024-02-01",
     *         "has_discounts": true
     *       }
     *     ]
     *   }
     * }
     * @response 404 scenario="客户不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="无门店权限" {
     *   "success": false,
     *   "message": "您没有权限访问任何门店的数据"
     * }
     * @response 403 scenario="无指定门店权限" {
     *   "success": false,
     *   "message": "您没有权限访问该门店的数据"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function debt($id)
    {
        $customer = Customer::findOrFail($id);

        // 验证客户所属门店在用户权限范围内
        $user = request()->user();
        if ($user && ! $user->isAdmin()) {
            $allowedStoreIds = $this->getUserStoreIds();
            if (! in_array($customer->store_id, $allowedStoreIds)) {
                return $this->errorResponse('无权限访问该客户', 403);
            }
        }

        // 获取用户有权限访问的门店ID列表
        $storeIds = $this->getUserStoreIds();

        if (empty($storeIds)) {
            return $this->errorResponse('您没有权限访问任何门店的数据', 403);
        }

        // 检查是否指定了特定门店，并验证权限
        $requestedStoreId = request()->input('store_id');
        if ($requestedStoreId && ! in_array($requestedStoreId, $storeIds)) {
            return $this->errorResponse('您没有权限访问该门店的数据', 403);
        }

        // 如果指定了门店，只使用该门店；否则使用用户有权限的所有门店
        $filterStoreIds = $requestedStoreId ? [$requestedStoreId] : $storeIds;

        // 只获取用户有权限访问的门店的未付账单
        $unpaidInvoices = $customer->unpaidInvoices()
            ->whereIn('store_id', $filterStoreIds)
            ->with(['discounts'])
            ->get();

        // 计算基于权限过滤的欠款统计
        $totalDebt = $customer->invoices()
            ->whereIn('store_id', $filterStoreIds)
            ->sum(DB::raw('amount - paid_amount'));

        // 计算实际总欠款（考虑优惠减免）
        $actualTotalDebt = $unpaidInvoices->sum(function ($invoice) {
            return max(0, $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount);
        });

        // 获取基于权限过滤的优惠减免统计
        $discountSummary = $this->getFilteredDiscountSummary($customer, $filterStoreIds);

        // 获取门店特定的欠款信息
        $storeDebtInfo = null;
        if ($requestedStoreId) {
            $storeDebtInfo = $customer->getStoreDebtInfo($requestedStoreId);
        } else {
            // 如果没有指定门店，提供所有有权限门店的汇总信息
            $storeDebtInfo = $this->getMultiStoreDebtInfo($customer, $filterStoreIds);
        }

        return $this->successResponse([
            'customer' => $customer->only(['id', 'name', 'phone']),
            'traditional_debt' => (float) $totalDebt,
            'actual_debt' => (float) $actualTotalDebt,
            'discount_summary' => $discountSummary,
            'store_debt_info' => $storeDebtInfo,
            'accessible_stores' => $storeIds, // 添加用户可访问的门店列表
            'unpaid_invoices' => $unpaidInvoices->map(function ($invoice) {
                // 计算实际剩余金额（考虑优惠减免）
                $actualRemaining = $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount;

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'store_id' => $invoice->store_id,
                    'amount' => number_format($invoice->amount, 2, '.', ''),
                    'paid_amount' => number_format($invoice->paid_amount, 2, '.', ''),
                    'discount_amount' => number_format($invoice->total_discount_amount, 2, '.', ''),
                    'actual_remaining' => number_format(max(0, $actualRemaining), 2, '.', ''),
                    'status' => $invoice->status,
                    'due_date' => $invoice->due_date,
                    'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
                    'has_discounts' => $invoice->hasDiscounts(),
                ];
            }),
        ]);
    }

    /**
     * 获取基于门店权限过滤的优惠减免统计
     */
    private function getFilteredDiscountSummary(Customer $customer, array $storeIds): array
    {
        $discounts = \App\Models\PaymentDiscount::whereHas('payment', function ($query) use ($customer, $storeIds) {
            $query->where('customer_id', $customer->id)
                ->whereIn('store_id', $storeIds);
        })->get();

        return [
            'total_count' => $discounts->count(),
            'total_amount' => (float) $discounts->sum('discount_amount'),
            'by_type' => $discounts->groupBy('discount_type')->map(function ($group, $type) {
                return [
                    'type' => $type,
                    'count' => $group->count(),
                    'amount' => (float) $group->sum('discount_amount'),
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * 获取多门店的欠款汇总信息
     */
    private function getMultiStoreDebtInfo(Customer $customer, array $storeIds): array
    {
        $invoices = $customer->invoices()->whereIn('store_id', $storeIds)->get();
        $unpaidInvoices = $invoices->whereIn('status', ['unpaid', 'partially_paid', 'overdue']);

        $totalAmount = $invoices->sum('amount');
        $paidAmount = $invoices->sum('paid_amount');
        $discountAmount = $invoices->sum('total_discount_amount');
        // 计算实际欠款（考虑优惠减免）
        $actualDebt = $unpaidInvoices->sum(function ($invoice) {
            return max(0, $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount);
        });

        return [
            'total_invoices' => $invoices->count(),
            'unpaid_invoices' => $unpaidInvoices->count(),
            'total_amount' => (float) $totalAmount,
            'paid_amount' => (float) $paidAmount,
            'discount_amount' => (float) $discountAmount,
            'traditional_debt' => (float) ($totalAmount - $paidAmount),
            'actual_debt' => (float) $actualDebt,
            'discount_rate' => $totalAmount > 0 ? round(($discountAmount / $totalAmount) * 100, 2) : 0.0,
            'store_count' => count($storeIds),
        ];
    }

    /**
     * 一键清账
     *
     * 为客户进行一键清账操作，自动分配还款到未付账单，差额自动创建减免记录。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @bodyParam payment_amount decimal required 实际收款金额 Example: 9500.00
     * @bodyParam store_id integer required 门店ID Example: 1
     * @bodyParam payment_method string 支付方式（cash/bank_transfer/alipay/wechat/other），默认cash Example: bank_transfer
     * @bodyParam remarks string 备注 Example: 一次性清账
     * @bodyParam apply_discount boolean 差额是否作为减免，默认true Example: true
     *
     * @response 200 scenario="清账成功" {
     *   "success": true,
     *   "data": {
     *     "payment": {
     *       "id": 123,
     *       "amount": "9500.00",
     *       "payment_number": "PAY202601030001"
     *     },
     *     "allocations": [
     *       { "invoice_id": 1, "amount": "3000.00" },
     *       { "invoice_id": 2, "amount": "3000.00" },
     *       { "invoice_id": 3, "amount": "3500.00" }
     *     ],
     *     "discounts": [
     *       { "invoice_id": 3, "amount": "500.00" }
     *     ],
     *     "summary": {
     *       "original_debt": "10000.00",
     *       "payment_received": "9500.00",
     *       "discount_applied": "500.00",
     *       "invoices_cleared": 3
     *     }
     *   },
     *   "message": "清账成功"
     * }
     * @response 422 scenario="无欠款" {
     *   "success": false,
     *   "message": "该客户在指定门店无待清账单"
     * }
     * @response 422 scenario="金额超出" {
     *   "success": false,
     *   "message": "收款金额超过总欠款"
     * }
     */
    public function clearDebt($id, Request $request)
    {
        $request->validate([
            'payment_amount' => 'required|numeric|min:0.01',
            'store_id' => 'required|integer|exists:stores,id',
            'payment_method' => 'nullable|string|in:cash,bank_transfer,alipay,wechat,other',
            'remarks' => 'nullable|string|max:500',
            'apply_discount' => 'nullable|boolean',
            'expected_debt' => 'nullable|numeric',  // 用于并发校验
        ]);

        $customer = Customer::findOrFail($id);

        // 验证门店权限
        $storeIds = $this->getUserStoreIds();
        $storeId = (int) $request->input('store_id');
        if (! in_array($storeId, $storeIds)) {
            return $this->errorResponse('您没有权限访问该门店的数据', 403);
        }

        $paymentAmount = (float) $request->input('payment_amount');
        $paymentMethod = $request->input('payment_method', 'cash');
        $remarks = $request->input('remarks', '一键清账');
        $applyDiscount = $request->input('apply_discount', true);

        // 获取未付账单（按优先级排序：逾期优先，然后按创建时间）
        $unpaidInvoices = $customer->invoices()
            ->where('store_id', $storeId)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->orderByRaw("CASE WHEN status = 'overdue' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'asc')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            return $this->errorResponse('该客户在指定门店无待清账单', 422);
        }

        // 计算实际总欠款（考虑已有减免）
        $totalDebt = $unpaidInvoices->sum(function ($invoice) {
            return max(0, $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount);
        });

        // 并发校验：前端传入的期望欠款与服务端实际欠款对比
        $expectedDebt = $request->input('expected_debt');
        if ($expectedDebt !== null && abs($totalDebt - (float) $expectedDebt) > 0.01) {
            return $this->errorResponse(
                '账单数据已变更，请刷新后重试',
                409,
                ['current_debt' => number_format($totalDebt, 2, '.', '')]
            );
        }

        if ($totalDebt <= 0) {
            return $this->errorResponse('该客户无待清欠款', 422);
        }

        if ($paymentAmount > $totalDebt) {
            return $this->errorResponse('收款金额超过总欠款 ('.number_format($totalDebt, 2).')', 422);
        }

        // 开始事务
        return DB::transaction(function () use ($customer, $storeId, $paymentAmount, $paymentMethod, $remarks, $applyDiscount, $unpaidInvoices, $totalDebt) {
            $user = Auth::user();

            // 生成唯一还款号
            $store = \App\Models\Store::find($storeId);
            $paymentNumber = 'PAY-'.($store->code ?? 'STORE').'-'.date('Ymd').'-'.\Illuminate\Support\Str::random(5);

            // 创建还款记录
            $payment = \App\Models\Payment::create([
                'payment_number' => $paymentNumber,
                'customer_id' => $customer->id,
                'store_id' => $storeId,
                'amount' => $paymentAmount,
                'allocated_amount' => 0,
                'payment_method' => $paymentMethod,
                'remarks' => $remarks,
                'received_by' => $user->id,
            ]);

            $allocations = [];
            $discounts = [];
            $remainingPayment = $paymentAmount;
            $invoicesCleared = 0;
            $totalDiscountApplied = 0;

            foreach ($unpaidInvoices as $invoice) {
                // 计算账单实际待付金额（考虑已有减免）
                $invoiceRemaining = max(0, $invoice->amount - $invoice->paid_amount - $invoice->total_discount_amount);

                if ($invoiceRemaining <= 0) {
                    continue;
                }

                // 分配金额
                $allocateAmount = min($remainingPayment, $invoiceRemaining);

                if ($allocateAmount > 0) {
                    // 创建分配记录
                    \App\Models\PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $allocateAmount,
                        'allocated_by' => $user->id,
                    ]);

                    // 更新账单已付金额
                    $invoice->paid_amount = $invoice->paid_amount + $allocateAmount;

                    $allocations[] = [
                        'invoice_id' => $invoice->id,
                        'amount' => number_format($allocateAmount, 2, '.', ''),
                    ];

                    $remainingPayment -= $allocateAmount;
                }

                // 计算差额（需要减免的金额）
                $discountNeeded = $invoiceRemaining - $allocateAmount;

                // 如果启用自动减免且有差额
                if ($applyDiscount && $discountNeeded > 0) {
                    // 创建减免记录
                    \App\Models\PaymentDiscount::create([
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'discount_amount' => $discountNeeded,
                        'discount_type' => 'write_off',
                        'reason' => '一键清账减免',
                        'approved_by' => $user->id,
                    ]);

                    $discounts[] = [
                        'invoice_id' => $invoice->id,
                        'amount' => number_format($discountNeeded, 2, '.', ''),
                    ];

                    $totalDiscountApplied += $discountNeeded;

                    // 更新账单为已付清
                    $invoice->status = 'paid';
                    $invoice->save();
                    $invoicesCleared++;
                } else {
                    // 更新账单状态
                    $invoice->updateStatus();

                    if ($invoice->status === 'paid') {
                        $invoicesCleared++;
                    }
                }
            }

            // 更新还款已分配金额
            $payment->allocated_amount = $paymentAmount;
            $payment->save();

            return $this->successResponse([
                'payment' => [
                    'id' => $payment->id,
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'payment_number' => $payment->payment_number,
                ],
                'allocations' => $allocations,
                'discounts' => $discounts,
                'summary' => [
                    'original_debt' => number_format((float) $totalDebt, 2, '.', ''),
                    'payment_received' => number_format((float) $paymentAmount, 2, '.', ''),
                    'discount_applied' => number_format($totalDiscountApplied, 2, '.', ''),
                    'invoices_cleared' => $invoicesCleared,
                ],
            ], '清账成功');
        });
    }

    /**
     * 获取客户今日未结账单汇总
     *
     * 返回指定客户在指定日期（默认今天）的未结清账单汇总数据，
     * 包含账单明细、开单人信息和门店收款二维码，用于生成分享图片。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @queryParam store_id integer 门店ID，不传则使用用户有权限的门店 Example: 1
     * @queryParam date string 日期(YYYY-MM-DD)，默认今天 Example: 2026-01-26
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "customer": {"id": 1, "name": "张三", "phone": "138****8001"},
     *     "store": {"id": 1, "name": "广州总店", "wechat_pay_code_data": "wxp://...", "alipay_code_data": "https://..."},
     *     "date": "2026-01-26",
     *     "summary": {"invoice_count": 2, "total_amount": "1500.00", "total_paid": "200.00", "total_remaining": "1300.00"},
     *     "invoices": [{"id": 1, "invoice_number": "INV-001", "created_at": "2026-01-26 09:30:15", "created_by": {"id": 5, "name": "李店员"}, "items": [...]}]
     *   }
     * }
     */
    public function dailyUnpaidSummary($id, Request $request)
    {
        $customer = Customer::findOrFail($id);

        // 验证客户所属门店在用户权限范围内
        $user = $request->user();
        if ($user && ! $user->isAdmin()) {
            $allowedStoreIds = $this->getUserStoreIds();
            if (! in_array($customer->store_id, $allowedStoreIds)) {
                return $this->errorResponse('无权限访问该客户', 403);
            }
        }

        // 获取用户有权限的门店
        $allowedStoreIds = $this->getUserStoreIds();
        if (empty($allowedStoreIds)) {
            return $this->errorResponse('您没有权限访问任何门店的数据', 403);
        }

        // 确定目标门店
        $requestedStoreId = $request->input('store_id');
        if ($requestedStoreId) {
            if (! in_array($requestedStoreId, $allowedStoreIds)) {
                return $this->errorResponse('您没有权限访问该门店的数据', 403);
            }
            $targetStoreId = (int) $requestedStoreId;
        } else {
            // 默认使用第一个有权限的门店
            $targetStoreId = $allowedStoreIds[0];
        }

        // 确定目标日期（默认今天）
        $dateInput = $request->input('date');
        try {
            $targetDate = $dateInput ? \Carbon\Carbon::parse($dateInput) : \Carbon\Carbon::today();
        } catch (\Exception $e) {
            return $this->errorResponse('日期格式无效，请使用 YYYY-MM-DD 格式', 422);
        }

        // 查询该客户在目标门店、目标日期的未结清账单
        $invoices = \App\Models\Invoice::with(['items', 'createdBy:id,name'])
            ->where('customer_id', $customer->id)
            ->where('store_id', $targetStoreId)
            ->whereDate('created_at', $targetDate)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->orderBy('created_at', 'asc')
            ->get();

        // 获取门店信息（含支付二维码）
        $store = \App\Models\Store::select('id', 'name', 'address', 'phone', 'wechat_pay_code_data', 'alipay_code_data')
            ->find($targetStoreId);

        // 计算汇总
        $totalAmount = $invoices->sum('amount');
        $totalPaid = $invoices->sum('paid_amount');
        $totalRemaining = $totalAmount - $totalPaid;

        // 格式化账单数据
        $formattedInvoices = $invoices->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                'paid_amount' => number_format((float) $invoice->paid_amount, 2, '.', ''),
                'remaining_amount' => number_format((float) ($invoice->amount - $invoice->paid_amount), 2, '.', ''),
                'status' => $invoice->status,
                'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
                'created_by' => $invoice->createdBy ? [
                    'id' => $invoice->createdBy->id,
                    'name' => $invoice->createdBy->name,
                ] : null,
                'items' => $invoice->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_name' => $item->item_name,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                        'subtotal' => number_format((float) $item->subtotal, 2, '.', ''),
                    ];
                }),
            ];
        });

        // 手机号脱敏处理
        $maskedPhone = $customer->phone;
        if ($maskedPhone && strlen($maskedPhone) >= 7) {
            $maskedPhone = substr($maskedPhone, 0, 3).'****'.substr($maskedPhone, -4);
        }

        return $this->successResponse([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $maskedPhone,
            ],
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'address' => $store->address,
                'phone' => $store->phone,
                'wechat_pay_code_data' => $store->wechat_pay_code_data,
                'alipay_code_data' => $store->alipay_code_data,
            ] : null,
            'date' => $targetDate->format('Y-m-d'),
            'summary' => [
                'invoice_count' => $invoices->count(),
                'total_amount' => number_format((float) $totalAmount, 2, '.', ''),
                'total_paid' => number_format((float) $totalPaid, 2, '.', ''),
                'total_remaining' => number_format((float) $totalRemaining, 2, '.', ''),
            ],
            'invoices' => $formattedInvoices,
        ]);
    }

    /**
     * 获取指定账单列表的汇总
     *
     * 根据传入的账单ID列表，生成账单汇总数据（支持跨日期，不支持跨门店）。
     *
     * @urlParam id integer required 客户ID Example: 1
     *
     * @bodyParam invoice_ids array required 账单ID列表 Example: [1, 2, 3]
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "date": "2026-01-01~2026-01-05",
     *     "..."
     *   }
     * }
     */
    public function summaryByInvoiceIds($id, Request $request)
    {
        $customer = Customer::findOrFail($id);
        $invoiceIds = $request->input('invoice_ids');

        if (empty($invoiceIds) || ! is_array($invoiceIds)) {
            return $this->errorResponse('请提供有效的账单ID列表', 422);
        }

        // 验证门店权限
        $allowedStoreIds = $this->getUserStoreIds();
        if (empty($allowedStoreIds)) {
            return $this->errorResponse('您没有权限访问任何门店的数据', 403);
        }

        // 查询账单
        $invoices = \App\Models\Invoice::with(['items', 'createdBy', 'discounts'])
            ->whereIn('id', $invoiceIds)
            ->where('customer_id', $customer->id)
            ->whereIn('store_id', $allowedStoreIds)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($invoices->isEmpty()) {
            return $this->errorResponse('未找到指定的账单', 404);
        }

        // 检查跨门店
        $uniqueStoreIds = $invoices->pluck('store_id')->unique();
        if ($uniqueStoreIds->count() > 1) {
            return $this->errorResponse('不支持合并不同门店的账单，请选择同一门店的账单进行分享', 422);
        }

        $targetStoreId = $uniqueStoreIds->first();
        $store = \App\Models\Store::select('id', 'name', 'address', 'phone', 'wechat_pay_code_data', 'alipay_code_data')
            ->find($targetStoreId);

        // 计算日期范围
        $minDate = $invoices->min('created_at');
        $maxDate = $invoices->max('created_at');
        $dateStr = $minDate->format('Y-m-d');
        if ($minDate->format('Y-m-d') !== $maxDate->format('Y-m-d')) {
            // 如果跨天，返回日期范围
            $dateStr = $minDate->format('m-d').'~'.$maxDate->format('m-d');
        }

        // 计算汇总
        $totalAmount = $invoices->sum('amount');
        $totalPaid = $invoices->sum('paid_amount');
        $totalRemaining = $invoices->sum('actual_remaining_amount');

        // 格式化账单数据
        $formattedInvoices = $invoices->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                'paid_amount' => number_format((float) $invoice->paid_amount, 2, '.', ''),
                'remaining_amount' => number_format((float) $invoice->actual_remaining_amount, 2, '.', ''),
                'status' => $invoice->status,
                'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
                'created_by' => $invoice->createdBy ? [
                    'id' => $invoice->createdBy->id,
                    'name' => $invoice->createdBy->name,
                ] : null,
                'items' => $invoice->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_name' => $item->item_name,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                        'subtotal' => number_format((float) $item->subtotal, 2, '.', ''),
                    ];
                }),
            ];
        });

        // 手机号脱敏
        $maskedPhone = $customer->phone;
        if ($maskedPhone && strlen($maskedPhone) >= 7) {
            $maskedPhone = substr($maskedPhone, 0, 3).'****'.substr($maskedPhone, -4);
        }

        return $this->successResponse([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $maskedPhone,
            ],
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'address' => $store->address,
                'phone' => $store->phone,
                'wechat_pay_code_data' => $store->wechat_pay_code_data,
                'alipay_code_data' => $store->alipay_code_data,
            ] : null,
            'date' => $dateStr,
            'summary' => [
                'invoice_count' => $invoices->count(),
                'total_amount' => number_format((float) $totalAmount, 2, '.', ''),
                'total_paid' => number_format((float) $totalPaid, 2, '.', ''),
                'total_remaining' => number_format((float) $totalRemaining, 2, '.', ''),
            ],
            'invoices' => $formattedInvoices,
        ]);
    }
}
