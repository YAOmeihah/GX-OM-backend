<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\Store;
use App\Services\CustomerStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @group 账单管理
 *
 * 账单的创建、查询、更新和删除操作
 */
class InvoiceController extends ApiController
{
    /**
     * 获取账单列表
     *
     * 获取账单分页列表，非管理员只能查看自己所属门店的账单。
     * 支持按门店、客户、状态和日期范围筛选。
     *
     * @queryParam store_id integer 按门店ID筛选 Example: 1
     * @queryParam customer_id integer 按客户ID筛选 Example: 1
     * @queryParam created_by integer 按创建人/经手人ID筛选 Example: 1
     * @queryParam status string 按状态筛选，可选值：unpaid(未付)、partially_paid(部分付款)、paid(已付清)、overdue(逾期) Example: unpaid
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     * @queryParam per_page integer 每页显示数量，默认15 Example: 15
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "invoice_number": "MAIN-20240101-ABC12",
     *         "store_id": 1,
     *         "customer_id": 1,
     *         "created_by": 1,
     *         "amount": "1000.00",
     *         "paid_amount": "500.00",
     *         "status": "partially_paid",
     *         "due_date": "2024-02-01",
     *         "description": "商品销售",
     *         "created_at": "2024-01-01T00:00:00.000000Z",
     *         "updated_at": "2024-01-01T00:00:00.000000Z",
     *         "store": {
     *           "id": 1,
     *           "name": "总店"
     *         },
     *         "customer": {
     *           "id": 1,
     *           "name": "张三",
     *           "phone": "13800138001"
     *         },
     *         "created_by": {
     *           "id": 1,
     *           "name": "管理员"
     *         }
     *       }
     *     ],
     *     "first_page_url": "http://localhost/api/invoices?page=1",
     *     "from": 1,
     *     "last_page": 5,
     *     "last_page_url": "http://localhost/api/invoices?page=5",
     *     "next_page_url": "http://localhost/api/invoices?page=2",
     *     "path": "http://localhost/api/invoices",
     *     "per_page": 15,
     *     "prev_page_url": null,
     *     "to": 15,
     *     "total": 75
     *   }
     * }
     * @response 403 scenario="无权限查看门店" {
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
        $query = Invoice::query();

        // 如果不是管理员，只能查看自己所属门店的账单
        if (! $this->isAdmin()) {
            $storeIds = $user->stores->pluck('id')->toArray();
            $query->whereIn('store_id', $storeIds);
        }

        // 按门店筛选
        if ($request->has('store_id')) {
            $storeId = $request->input('store_id');
            // 验证用户是否有权限查看该门店的账单
            if (! $this->isAdmin() && ! $this->belongsToStore($storeId)) {
                return $this->errorResponse('权限不足', 403);
            }
            $query->where('store_id', $storeId);
        }

        // 按客户筛选
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // 未结清筛选 (unpaid + partially_paid + overdue)
        if ($request->boolean('outstanding_only')) {
            $query->whereIn('status', ['unpaid', 'partially_paid', 'overdue']);
        }

        // 按日期范围筛选
        if ($request->has('start_date')) {
            $query->where('created_at', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('created_at', '<=', $request->input('end_date').' 23:59:59');
        }

        // 金额范围筛选
        if ($request->has('min_amount')) {
            $query->where('amount', '>=', $request->input('min_amount'));
        }

        if ($request->has('max_amount')) {
            $query->where('amount', '<=', $request->input('max_amount'));
        }

        // 按经手人筛选
        if ($request->has('created_by')) {
            $query->where('created_by', $request->input('created_by'));
        }

        // 加载关联数据（包含减免记录、商品明细和附件）
        $query->with(['store:id,name', 'customer:id,name,phone', 'createdBy:id,name', 'discounts', 'items', 'attachments']);

        // 排序
        $query->orderBy('created_at', 'desc');

        // 分页
        $invoices = $query->paginate($request->input('per_page', 15));

        // 为每个账单添加 total_discount_amount
        $invoices->getCollection()->transform(function ($invoice) {
            $invoice->total_discount_amount = $invoice->total_discount_amount;
            $invoice->actual_remaining_amount = $invoice->actual_remaining_amount;

            return $invoice;
        });

        return $this->successResponse($invoices);
    }

    /**
     * 创建账单
     *
     * 创建新的账单记录。可以直接指定总金额，或提供明细项目列表。
     * 如果提供了明细项目，系统会自动计算总金额。
     *
     * @bodyParam store_id integer required 门店ID Example: 1
     * @bodyParam customer_id integer required 客户ID Example: 1
     * @bodyParam amount number 账单总金额（如果不提供明细项目则必填），最小0.01 Example: 1000.00
     * @bodyParam due_date string 到期日期(YYYY-MM-DD格式) Example: 2024-02-01
     * @bodyParam description string 账单描述/备注 Example: 商品销售
     * @bodyParam items array 账单明细项目列表
     * @bodyParam items[].item_name string 项目名称，最大255字符 Example: 商品A
     * @bodyParam items[].item_description string 项目描述 Example: 优质商品
     * @bodyParam items[].quantity number required 数量，最小0.001 Example: 2
     * @bodyParam items[].unit_price number required 单价，最小0.01 Example: 500.00
     * @bodyParam items[].sort_order integer 排序号，最小0 Example: 0
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "invoice_number": "MAIN-20240101-ABC12",
     *     "store_id": 1,
     *     "customer_id": 1,
     *     "created_by": 1,
     *     "amount": "1000.00",
     *     "paid_amount": "0.00",
     *     "status": "unpaid",
     *     "due_date": "2024-02-01",
     *     "description": "商品销售",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z",
     *     "items": [
     *       {
     *         "id": 1,
     *         "invoice_id": 1,
     *         "item_name": "商品A",
     *         "item_description": "优质商品",
     *         "quantity": "2.000",
     *         "unit_price": "500.00",
     *         "subtotal": "1000.00",
     *         "sort_order": 0
     *       }
     *     ]
     *   },
     *   "message": "账单创建成功"
     * }
     * @response 403 scenario="无权限" {
     *   "success": false,
     *   "message": "您没有权限在此门店创建账单"
     * }
     * @response 422 scenario="缺少金额或明细" {
     *   "success": false,
     *   "message": "必须提供账单金额或明细项目"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "store_id": ["门店不存在"],
     *     "customer_id": ["客户不存在"]
     *   }
     * }
     * @response 500 scenario="创建失败" {
     *   "success": false,
     *   "message": "账单创建失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function store(StoreInvoiceRequest $request)
    {
        $user = Auth::user();

        // 权限检查和验证已在 StoreInvoiceRequest 中完成
        $validated = $request->validated();

        // 生成唯一账单号
        $store = Store::find($validated['store_id']);
        $invoiceNumber = $store->code.'-'.date('Ymd').'-'.Str::random(5);

        $invoice = null;

        DB::beginTransaction();

        try {
            // 使用 withoutAuditingDo 避免创建过程中的中间日志
            // 我们将在事务提交后手动记录一条完整的创建日志
            $invoice = Invoice::withoutAuditingDo(function () use ($validated, $invoiceNumber, $user) {
                // 预先计算总金额
                $totalAmount = 0;
                $itemsToCreate = [];

                if (isset($validated['items'])) {
                    foreach ($validated['items'] as $index => $itemData) {
                        $qty = $itemData['quantity'];
                        $price = $itemData['unit_price'];
                        $subtotal = $qty * $price;
                        $totalAmount += $subtotal;

                        $itemsToCreate[] = array_merge($itemData, [
                            'subtotal' => $subtotal,
                            'sort_order' => $itemData['sort_order'] ?? $index,
                        ]);
                    }
                } elseif (isset($validated['amount'])) {
                    $totalAmount = $validated['amount'];
                    $itemsToCreate[] = [
                        'item_name' => '账单项目',
                        'item_description' => $validated['description'] ?? null,
                        'quantity' => 1,
                        'unit_price' => $validated['amount'],
                        'subtotal' => $validated['amount'],
                        'sort_order' => 0,
                    ];
                }

                // 创建账单
                $invoice = Invoice::create([
                    'invoice_number' => $invoiceNumber,
                    'store_id' => $validated['store_id'],
                    'customer_id' => $validated['customer_id'],
                    'created_by' => $user->id,
                    'amount' => $totalAmount,
                    // 'invoice_date' => $validated['invoice_date'], - removed
                    'due_date' => $validated['due_date'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => 'unpaid',
                ]);

                // 创建明细
                foreach ($itemsToCreate as $itemData) {
                    $invoice->items()->create($itemData);
                }

                return $invoice;
            });

            DB::commit();

            // 加载明细数据
            $invoice->load(['items', 'createdBy:id,name', 'customer:id,name,phone']);

            // 确保 created_by 返回的是对象而不是 ID
            if ($invoice->relationLoaded('createdBy')) {
                $invoice->setAttribute('created_by', $invoice->createdBy);
            }

            // 手动记录一条完整的创建审计日志
            // 这确保了我们记录的是最终状态（包括计算后的总金额），而不是初始的0金额
            // 并且避免了添加明细时触发的多次“更新”日志
            try {
                app(\App\Services\AuditLogService::class)->logCreate($invoice);
            } catch (\Exception $e) {
                \Log::error('手动记录审计日志失败: '.$e->getMessage());
            }

            return $this->successResponse($invoice, '账单创建成功', 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse('账单创建失败：'.$e->getMessage(), 500);
        }
    }

    /**
     * 获取账单详情
     *
     * 获取指定账单的详细信息，包括门店、客户、创建者、明细项目、付款分配记录和附件。
     *
     * @urlParam id integer required 账单ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "invoice_number": "MAIN-20240101-ABC12",
     *     "store_id": 1,
     *     "customer_id": 1,
     *     "created_by": 1,
     *     "amount": "1000.00",
     *     "paid_amount": "500.00",
     *     "status": "partially_paid",
     *     "due_date": "2024-02-01",
     *     "description": "商品销售",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z",
     *     "store": {
     *       "id": 1,
     *       "name": "总店"
     *     },
     *     "customer": {
     *       "id": 1,
     *       "name": "张三",
     *       "phone": "13800138001"
     *     },
     *     "created_by": {
     *       "id": 1,
     *       "name": "管理员"
     *     },
     *     "items": [
     *       {
     *         "id": 1,
     *         "invoice_id": 1,
     *         "item_name": "商品A",
     *         "item_description": "优质商品",
     *         "quantity": "2.000",
     *         "unit_price": "500.00",
     *         "subtotal": "1000.00",
     *         "sort_order": 0
     *       }
     *     ],
     *     "payment_allocations": [
     *       {
     *         "id": 1,
     *         "payment_id": 1,
     *         "invoice_id": 1,
     *         "amount": "500.00",
     *         "allocated_by": {
     *           "id": 1,
     *           "name": "管理员"
     *         },
     *         "created_at": "2024-01-05T00:00:00.000000Z",
     *         "payment": {
     *           "id": 1,
     *           "payment_number": "PAY-MAIN-20240105-XYZ99",
     *           "amount": "500.00"
     *         }
     *       }
     *     ],
     *     "attachments": [
     *       {
     *         "id": 1,
     *         "filename": "abc123.jpg",
     *         "original_filename": "收据照片.jpg",
     *         "file_path": "invoices/abc123.jpg",
     *         "file_size": 102400,
     *         "mime_type": "image/jpeg",
     *         "url": "https://storage.example.com/invoices/abc123.jpg"
     *       }
     *     ]
     *   }
     * }
     * @response 404 scenario="账单不存在" {
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
        $invoice = Invoice::with([
            'store:id,name',
            'customer:id,name,phone',
            'createdBy:id,name',
            'items',
            'paymentAllocations.payment',
            'paymentAllocations.allocatedBy:id,name',
            'attachments',
            'discounts.payment',  // 加载减免记录
        ])->findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('view', $invoice);

        // 添加总减免金额到响应
        $invoiceArray = $invoice->toArray();
        $invoiceArray['total_discount_amount'] = $invoice->total_discount_amount;
        $invoiceArray['actual_remaining_amount'] = $invoice->actual_remaining_amount;

        return $this->successResponse($invoiceArray);
    }

    /**
     * 更新账单
     *
     * 更新指定账单的信息。如果账单已有付款记录，则只能更新描述字段。
     * 需要系统管理员或该门店店长权限。
     *
     * @urlParam id integer required 账单ID Example: 1
     *
     * @bodyParam amount number 账单总金额（仅无付款时可修改），最小0.01 Example: 1500.00
     * @bodyParam due_date string 到期日期(YYYY-MM-DD格式)（仅无付款时可修改） Example: 2024-02-15
     * @bodyParam description string 账单描述/备注 Example: 商品销售（已更新）
     * @bodyParam items array 账单明细项目列表（仅无付款时可修改）
     * @bodyParam items[].item_name string 项目名称，最大255字符 Example: 商品B
     * @bodyParam items[].item_description string 项目描述 Example: 高端商品
     * @bodyParam items[].quantity number required 数量，最小0.001 Example: 3
     * @bodyParam items[].unit_price number required 单价，最小0.01 Example: 500.00
     * @bodyParam items[].sort_order integer 排序号，最小0 Example: 0
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "invoice_number": "MAIN-20240101-ABC12",
     *     "store_id": 1,
     *     "customer_id": 1,
     *     "amount": "1500.00",
     *     "paid_amount": "0.00",
     *     "status": "unpaid",
     *     "due_date": "2024-02-15",
     *     "description": "商品销售（已更新）",
     *     "items": [
     *       {
     *         "id": 2,
     *         "item_name": "商品B",
     *         "quantity": "3.000",
     *         "unit_price": "500.00",
     *         "subtotal": "1500.00"
     *       }
     *     ]
     *   },
     *   "message": "账单更新成功"
     * }
     * @response 404 scenario="账单不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 500 scenario="更新失败" {
     *   "success": false,
     *   "message": "账单更新失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function update(UpdateInvoiceRequest $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        // 权限检查和验证已在 UpdateInvoiceRequest 中完成
        $validated = $request->validated();

        $shouldSyncStats = ! empty(array_intersect(array_keys($validated), ['customer_id', 'amount', 'items']));
        $originalCustomerId = $invoice->customer_id;
        $originalStoreId = $invoice->store_id;

        // 获取更新前的原始数据用于审计（含明细快照，带 line_uid）
        $invoice->loadMissing('items');
        $originalData = array_merge($invoice->toArray(), [
            'items' => $invoice->items->map(fn ($item) => [
                'line_uid' => $item->line_uid,
                'item_name' => $item->item_name,
                'item_description' => $item->item_description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
                'sort_order' => $item->sort_order,
            ])->toArray(),
        ]);

        DB::beginTransaction();

        try {
            // 使用 withoutAuditingDo 避免更新过程中的中间日志
            Invoice::withoutAuditingDo(function () use ($invoice, $validated) {
                // 预先计算总金额和准备明细
                $totalAmount = 0;
                $itemsToCreate = [];
                $shouldUpdateItems = false;

                if (isset($validated['items']) && $invoice->paid_amount == 0) {
                    $shouldUpdateItems = true;

                    // 按 line_uid 建旧明细索引
                    $existingItems = $invoice->items->keyBy('line_uid');
                    $incomingUids = [];

                    foreach ($validated['items'] as $index => $itemData) {
                        $qty = $itemData['quantity'];
                        $price = $itemData['unit_price'];
                        $subtotal = $qty * $price;
                        $totalAmount += $subtotal;

                        $lineUid = $itemData['line_uid'] ?? null;

                        if ($lineUid && $existingItems->has($lineUid)) {
                            // 更新已有明细
                            $incomingUids[] = $lineUid;
                            $existingItems[$lineUid]->update([
                                'item_name' => array_key_exists('item_name', $itemData)
                                    ? $itemData['item_name']
                                    : $existingItems[$lineUid]->item_name,
                                'item_description' => array_key_exists('item_description', $itemData)
                                    ? $itemData['item_description']
                                    : $existingItems[$lineUid]->item_description,
                                'quantity' => $qty,
                                'unit_price' => $price,
                                'sort_order' => $itemData['sort_order'] ?? $index,
                            ]);
                        } else {
                            // 新增明细（line_uid 由模型 creating 事件自动生成）
                            $newItem = $invoice->items()->create([
                                'item_name' => $itemData['item_name'] ?? null,
                                'item_description' => $itemData['item_description'] ?? null,
                                'quantity' => $qty,
                                'unit_price' => $price,
                                'sort_order' => $itemData['sort_order'] ?? $index,
                            ]);
                            $incomingUids[] = $newItem->line_uid;
                        }
                    }

                    // 删除不在新列表里的旧明细
                    $existingItems->each(function ($item) use ($incomingUids) {
                        if (! in_array($item->line_uid, $incomingUids)) {
                            $item->delete();
                        }
                    });

                } elseif (isset($validated['amount']) && ! $invoice->hasItems()) {
                    $shouldUpdateItems = true;
                    $totalAmount = $validated['amount'];
                    $invoice->items()->create([
                        'item_name' => '账单项目',
                        'item_description' => $validated['description'] ?? null,
                        'quantity' => 1,
                        'unit_price' => $validated['amount'],
                        'sort_order' => 0,
                    ]);
                }

                // 更新账单基本信息
                $basicData = $validated;
                unset($basicData['items']);

                if ($shouldUpdateItems) {
                    $basicData['amount'] = $totalAmount;
                }

                $invoice->update($basicData);
            });

            DB::commit();

            // 加载明细和创建者数据，获取最新状态
            $invoice->refresh();
            $invoice->load(['items', 'createdBy:id,name', 'customer:id,name,phone']);

            // 确保 created_by 返回的是对象而不是 ID
            if ($invoice->relationLoaded('createdBy')) {
                $invoice->setAttribute('created_by', $invoice->createdBy);
            }

            if ($shouldSyncStats) {
                $statsService = app(CustomerStatsService::class);
                $statsService->syncCustomerStoreStats($originalCustomerId, $originalStoreId);
                if ($originalCustomerId !== $invoice->customer_id || $originalStoreId !== $invoice->store_id) {
                    $statsService->syncCustomerStoreStats($invoice->customer_id, $invoice->store_id);
                }
            }

            // 手动记录一条完整的更新审计日志
            try {
                $newData = array_merge($invoice->toArray(), [
                    'items' => $invoice->items->map(fn ($item) => [
                        'line_uid' => $item->line_uid,
                        'item_name' => $item->item_name,
                        'item_description' => $item->item_description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'subtotal' => $item->subtotal,
                        'sort_order' => $item->sort_order,
                    ])->toArray(),
                ]);
                app(\App\Services\AuditLogService::class)->logUpdate($invoice, $originalData, null, $newData);
            } catch (\Exception $e) {
                \Log::error('手动记录审计日志失败: '.$e->getMessage());
            }

            return $this->successResponse($invoice, '账单更新成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse('账单更新失败：'.$e->getMessage(), 500);
        }
    }

    /**
     * 删除账单
     *
     * 删除指定账单。需要系统管理员或该门店店长权限。
     * 如果账单已有付款记录则无法删除。
     *
     * @urlParam id integer required 账单ID Example: 1
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "账单删除成功"
     * }
     * @response 404 scenario="账单不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限或店长权限"
     * }
     * @response 422 scenario="已有付款记录" {
     *   "success": false,
     *   "message": "该账单已有付款记录，无法删除"
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
        $invoice = Invoice::with('items')->findOrFail($id);

        // 使用 Policy 进行权限检查
        $this->authorize('delete', $invoice);

        // 如果账单已经有财务活动，则不能删除
        if ($invoice->hasFinancialActivity()) {
            return $this->errorResponse('该账单已有财务活动，无法删除', 422);
        }

        // 在删除前手动记录审计日志（因为删除 items 后就无法获取明细了）
        try {
            app(\App\Services\AuditLogService::class)->logDelete($invoice);
        } catch (\Exception $e) {
            \Log::error('手动记录删除审计日志失败: '.$e->getMessage());
        }

        // 使用事务确保数据一致性
        DB::transaction(function () use ($invoice) {
            // 删除关联的附件（会触发 Attachment 模型的 deleting 事件清理存储文件）
            $invoice->attachments()->each(function ($attachment) {
                $attachment->delete();
            });

            // 删除关联的明细项目
            $invoice->items()->delete();

            // 删除关联的优惠减免记录
            $invoice->discounts()->delete();

            // 删除账单本身，使用 deleteQuietly 避免触发 Auditable 重复记录审计日志
            $invoice->deleteQuietly();
        });

        // 事务提交后手动同步客户欠款统计
        app(CustomerStatsService::class)->syncCustomerStoreStats(
            $invoice->customer_id,
            $invoice->store_id
        );

        return $this->successResponse(null, '账单删除成功');
    }
}
