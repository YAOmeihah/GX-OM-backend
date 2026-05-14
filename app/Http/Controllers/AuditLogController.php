<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * @group 审计日志
 *
 * 系统操作审计日志的查询和统计
 */
class AuditLogController extends ApiController
{
    protected AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * 获取审计日志列表
     *
     * 获取审计日志的分页列表，支持多种筛选条件。
     * 管理员可查看所有日志，其他用户只能查看所属门店的日志。
     *
     * @queryParam user_id integer 按用户ID筛选 Example: 1
     * @queryParam store_id integer 按门店ID筛选 Example: 1
     * @queryParam action string 按操作类型筛选，可选值：login、logout、create、update、delete、allocate、revoke、discount Example: create
     * @queryParam auditable_type string 按模型类型筛选，可选值：invoice、payment、customer、store、user、attachment等 Example: invoice
     * @queryParam auditable_id integer 按模型ID筛选 Example: 1
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     * @queryParam is_success boolean 按成功/失败筛选 Example: true
     * @queryParam search string 搜索关键词，可搜索用户名、描述、IP地址等 Example: 张三
     * @queryParam sort_by string 排序字段，默认created_at Example: created_at
     * @queryParam sort_order string 排序方向，可选值：asc、desc，默认desc Example: desc
     * @queryParam per_page integer 每页显示数量，最大100，默认15 Example: 15
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "user_id": 1,
     *         "store_id": 1,
     *         "action": "create",
     *         "auditable_type": "App\\Models\\Invoice",
     *         "auditable_id": 1,
     *         "auditable_label": "账单 MAIN-20240101-ABC12",
     *         "user_name": "管理员",
     *         "description": "创建账单",
     *         "old_values": null,
     *         "new_values": {"amount": "1000.00", "status": "unpaid"},
     *         "ip_address": "192.168.1.1",
     *         "user_agent": "Mozilla/5.0...",
     *         "is_success": true,
     *         "created_at": "2024-01-01T00:00:00.000000Z",
     *         "user": {
     *           "id": 1,
     *           "name": "管理员"
     *         },
     *         "store": {
     *           "id": 1,
     *           "name": "总店"
     *         }
     *       }
     *     ],
     *     "first_page_url": "http://localhost/api/audit-logs?page=1",
     *     "from": 1,
     *     "last_page": 10,
     *     "per_page": 15,
     *     "total": 150
     *   }
     * }
     * @response 403 scenario="无门店权限" {
     *   "success": false,
     *   "message": "您没有权限访问审计日志"
     * }
     * @response 403 scenario="无指定门店权限" {
     *   "success": false,
     *   "message": "无权访问该门店的日志"
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
        // 权限检查已由路由中间件 permission:audit-logs.view 处理
        // 此处根据角色和视角参数确定数据范围

        $query = AuditLog::with([
            'user:id,name',
            'businessStore:id,name',
            'actorStore:id,name',
        ]);

        // 获取视角参数
        $viewScope = $request->get('view_scope', 'store'); // store|global|all
        $storeId = $request->get('store_id');

        if ($this->isAdmin()) {
            // 管理员可以选择视角
            if ($viewScope === 'store') {
                // 查看门店业务日志
                $query->where('scope_type', 'store');

                if ($storeId) {
                    $query->where('business_store_id', $storeId);
                }
            } elseif ($viewScope === 'global') {
                // 查看全局日志
                $query->where('scope_type', 'global');
            }
            // viewScope === 'all' 不过滤作用域
        } else {
            // 非管理员只能查看所属门店的业务日志
            $storeIds = $this->getUserStoreIds();

            $query->where('scope_type', 'store')
                ->whereIn('business_store_id', $storeIds);

            // 如果指定了门店，进一步过滤
            if ($storeId && in_array($storeId, $storeIds)) {
                $query->where('business_store_id', $storeId);
            } elseif ($storeId) {
                return $this->errorResponse('无权访问该门店的日志', 403);
            }
        }

        // 筛选条件
        if ($request->filled('user_id')) {
            $query->byUser($request->user_id);
        }

        if ($request->filled('action')) {
            $query->byAction($request->action);
        }

        if ($request->filled('auditable_type')) {
            $modelType = $this->resolveModelType($request->auditable_type);
            $query->byModel($modelType);
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', $request->auditable_id);
        }

        if ($request->filled('start_date') || $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->filled('is_success')) {
            $request->is_success ? $query->successful() : $query->failed();
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('auditable_label', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // 排序
        $allowedSortFields = ['created_at', 'action', 'user_name', 'auditable_type'];
        $allowedSortOrders = ['asc', 'desc'];
        $sortField = in_array($request->get('sort_by'), $allowedSortFields) ? $request->get('sort_by') : 'created_at';
        $sortOrder = in_array(strtolower($request->get('sort_order', 'desc')), $allowedSortOrders) ? strtolower($request->get('sort_order', 'desc')) : 'desc';
        $query->orderBy($sortField, $sortOrder);

        // 分页
        $perPage = min($request->get('per_page', 15), 100);
        $logs = $query->paginate($perPage);

        // 为列表项附加轻量摘要字段（从 change_payload 派生）
        $logs->getCollection()->transform(function ($log) {
            $logArray = $log->toArray();

            if (! empty($log->change_payload)) {
                $payload = $log->change_payload;

                $logArray['has_structured_changes'] = true;
                $logArray['summary_title'] = $payload['summary']['title'] ?? null;
                $logArray['summary_subtitle'] = $payload['summary']['subtitle'] ?? null;
                $logArray['summary_highlights'] = $payload['summary']['highlights'] ?? [];
                $logArray['change_stats'] = $payload['stats'] ?? null;
            } else {
                $logArray['has_structured_changes'] = false;
            }

            return $logArray;
        });

        return $this->successResponse($logs);
    }

    /**
     * 获取审计日志详情
     *
     * 获取指定审计日志的详细信息，包括变更前后的数据对比。
     *
     * @urlParam id integer required 审计日志ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "log": {
     *       "id": 1,
     *       "user_id": 1,
     *       "store_id": 1,
     *       "action": "update",
     *       "auditable_type": "App\\Models\\Invoice",
     *       "auditable_id": 1,
     *       "auditable_label": "账单 MAIN-20240101-ABC12",
     *       "user_name": "管理员",
     *       "description": "更新账单",
     *       "old_values": {"amount": "1000.00"},
     *       "new_values": {"amount": "1500.00"},
     *       "ip_address": "192.168.1.1",
     *       "user_agent": "Mozilla/5.0...",
     *       "is_success": true,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "user": {
     *         "id": 1,
     *         "name": "管理员",
     *         "email": "admin@example.com"
     *       },
     *       "store": {
     *         "id": 1,
     *         "name": "总店",
     *         "code": "MAIN"
     *       }
     *     },
     *     "formatted_changes": [
     *       {
     *         "field": "amount",
     *         "old_value": "1000.00",
     *         "new_value": "1500.00"
     *       }
     *     ]
     *   }
     * }
     * @response 404 scenario="日志不存在" {
     *   "success": false,
     *   "message": "审计日志不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "无权查看此日志"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function show(int $id)
    {
        $log = AuditLog::with(['user:id,name,email'])->find($id);

        if (! $log) {
            return $this->errorResponse('审计日志不存在', 404);
        }

        // 非管理员需验证门店访问权限
        if (! $this->isAdmin()) {
            // 全局日志只有管理员可见
            if ($log->scope_type === 'global') {
                return $this->errorResponse('无权查看此日志', 403);
            }

            // 门店业务日志需验证归属权限
            if ($log->scope_type === 'store') {
                if ($log->business_store_id === null || ! $this->belongsToStore($log->business_store_id)) {
                    return $this->errorResponse('无权查看此日志', 403);
                }
            }
        }

        return $this->successResponse([
            'log' => $log,
            'formatted_changes' => $log->formatted_changes,
            'change_payload' => $log->change_payload,
        ]);
    }

    /**
     * 获取审计统计数据
     *
     * 获取审计日志的统计数据，包括按操作类型、模型类型分组的统计。
     *
     * @queryParam store_id integer 门店ID，不传则返回用户所属门店的统计 Example: 1
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "total_logs": 1000,
     *     "success_count": 980,
     *     "failure_count": 20,
     *     "by_action": {
     *       "create": 300,
     *       "update": 400,
     *       "delete": 50,
     *       "login": 200,
     *       "logout": 50
     *     },
     *     "by_model": {
     *       "App\\Models\\Invoice": 400,
     *       "App\\Models\\Payment": 300,
     *       "App\\Models\\Customer": 200,
     *       "App\\Models\\User": 100
     *     },
     *     "action_labels": {
     *       "login": "登录",
     *       "logout": "登出",
     *       "create": "创建",
     *       "update": "更新",
     *       "delete": "删除"
     *     },
     *     "model_labels": {
     *       "App\\Models\\Invoice": "账单",
     *       "App\\Models\\Payment": "还款",
     *       "App\\Models\\Customer": "客户"
     *     }
     *   },
     *   "message": "审计统计获取成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "您没有权限访问审计统计"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function statistics(Request $request)
    {
        $storeId = null;
        $viewScope = $request->get('view_scope', 'all');

        if (! $this->isAdmin()) {
            $storeIds = $this->getUserStoreIds();

            // 如果请求指定了 store_id，验证权限后使用
            if ($request->filled('store_id')) {
                if (in_array($request->store_id, $storeIds)) {
                    $storeId = $request->store_id;
                } else {
                    return $this->errorResponse('无权访问该门店的统计数据', 403);
                }
            }
            // 如果没有指定 store_id，要求必须指定（避免默认取第一个导致口径不一致）
            else {
                return $this->errorResponse('请指定要查看的门店', 400);
            }

            // 非管理员强制只能查看门店业务日志
            $viewScope = 'store';
        } elseif ($request->filled('store_id')) {
            $storeId = $request->store_id;
        }

        $statistics = $this->auditLogService->getStatistics(
            $storeId,
            $request->start_date,
            $request->end_date,
            $viewScope
        );

        // 添加操作类型标签
        $statistics['action_labels'] = AuditLog::ACTION_LABELS;
        $statistics['model_labels'] = AuditLog::MODEL_LABELS;

        return $this->successResponse($statistics, '审计统计获取成功');
    }

    /**
     * 获取模型的审计历史
     *
     * 获取指定模型实例的完整审计历史记录。
     *
     * @queryParam auditable_type string required 模型类型，可选值：invoice、payment、customer、store、user等 Example: invoice
     * @queryParam auditable_id integer required 模型ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "auditable_type": "invoice",
     *     "auditable_id": 1,
     *     "model_label": "账单",
     *     "history": [
     *       {
     *         "id": 10,
     *         "user_id": 1,
     *         "action": "update",
     *         "description": "更新账单金额",
     *         "old_values": {"amount": "1000.00"},
     *         "new_values": {"amount": "1500.00"},
     *         "created_at": "2024-01-02T00:00:00.000000Z",
     *         "user": {"id": 1, "name": "管理员"}
     *       },
     *       {
     *         "id": 1,
     *         "user_id": 1,
     *         "action": "create",
     *         "description": "创建账单",
     *         "old_values": null,
     *         "new_values": {"amount": "1000.00", "status": "unpaid"},
     *         "created_at": "2024-01-01T00:00:00.000000Z",
     *         "user": {"id": 1, "name": "管理员"}
     *       }
     *     ]
     *   }
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "无权查看此记录的审计历史"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "auditable_type": ["模型类型不能为空"],
     *     "auditable_id": ["模型ID不能为空"]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'auditable_type' => 'required|string',
            'auditable_id' => 'required|integer',
        ]);

        $modelType = $this->resolveModelType($validated['auditable_type']);

        // 非管理员：限制只能查看所属门店的日志
        if (! $this->isAdmin()) {
            $storeIds = $this->getUserStoreIds();
        }

        $query = AuditLog::with(['user:id,name'])
            ->where('auditable_type', $modelType)
            ->where('auditable_id', $validated['auditable_id']);

        // 非管理员限制只能查看所属门店的业务日志
        if (! $this->isAdmin()) {
            $query->where('scope_type', 'store')
                ->whereIn('business_store_id', $storeIds);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        return $this->successResponse([
            'auditable_type' => $validated['auditable_type'],
            'auditable_id' => $validated['auditable_id'],
            'model_label' => AuditLog::MODEL_LABELS[$modelType] ?? class_basename($modelType),
            'history' => $logs,
        ]);
    }

    /**
     * 获取筛选选项
     *
     * 获取审计日志筛选时可用的操作类型和模型类型选项。
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "actions": [
     *       {"value": "login", "label": "登录"},
     *       {"value": "logout", "label": "登出"},
     *       {"value": "create", "label": "创建"},
     *       {"value": "update", "label": "更新"},
     *       {"value": "delete", "label": "删除"},
     *       {"value": "allocate", "label": "分配"},
     *       {"value": "revoke", "label": "撤销"},
     *       {"value": "discount", "label": "优惠减免"}
     *     ],
     *     "models": [
     *       {"value": "invoice", "label": "账单", "full_class": "App\\Models\\Invoice"},
     *       {"value": "payment", "label": "还款", "full_class": "App\\Models\\Payment"},
     *       {"value": "customer", "label": "客户", "full_class": "App\\Models\\Customer"},
     *       {"value": "store", "label": "门店", "full_class": "App\\Models\\Store"},
     *       {"value": "user", "label": "用户", "full_class": "App\\Models\\User"}
     *     ]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function filters(Request $request)
    {
        // 构建基础查询
        $query = AuditLog::query();

        // 获取视角参数
        $viewScope = $request->get('view_scope', 'store');
        $storeId = $request->get('store_id');

        if ($this->isAdmin()) {
            // 管理员可以选择视角
            if ($viewScope === 'store') {
                $query->where('scope_type', 'store');

                if ($storeId) {
                    $query->where('business_store_id', $storeId);
                }
            } elseif ($viewScope === 'global') {
                $query->where('scope_type', 'global');
            }
            // viewScope === 'all' 不过滤
        } else {
            // 非管理员只能看门店业务日志的筛选项
            $storeIds = $this->getUserStoreIds();

            $query->where('scope_type', 'store')
                ->whereIn('business_store_id', $storeIds);

            // 如果指定了门店，进一步过滤
            if ($storeId && in_array($storeId, $storeIds)) {
                $query->where('business_store_id', $storeId);
            } elseif ($storeId) {
                return $this->errorResponse('无权访问该门店的筛选项', 403);
            }
        }

        // 获取实际存在的操作类型
        $existingActions = (clone $query)
            ->select('action')
            ->distinct()
            ->pluck('action')
            ->filter(function ($action) {
                return isset(AuditLog::ACTION_LABELS[$action]);
            });

        // 获取实际存在的模型类型
        $existingModels = (clone $query)
            ->select('auditable_type')
            ->distinct()
            ->whereNotNull('auditable_type')
            ->pluck('auditable_type')
            ->filter(function ($type) {
                return isset(AuditLog::MODEL_LABELS[$type]);
            });

        return $this->successResponse([
            'actions' => $existingActions->map(function ($action) {
                return [
                    'value' => $action,
                    'label' => AuditLog::ACTION_LABELS[$action],
                ];
            })->values(),
            'models' => $existingModels->map(function ($type) {
                return [
                    'value' => $this->getModelShortName($type),
                    'label' => AuditLog::MODEL_LABELS[$type],
                    'full_class' => $type,
                ];
            })->values(),
        ]);
    }

    /**
     * 获取用户操作日志
     *
     * 获取指定用户的操作日志。非管理员只能查看自己的日志。
     *
     * @urlParam userId integer 用户ID（管理员可指定，非管理员忽略此参数） Example: 1
     *
     * @queryParam user_id integer 用户ID（管理员用，URL参数优先） Example: 1
     * @queryParam start_date string 开始日期(YYYY-MM-DD格式) Example: 2024-01-01
     * @queryParam end_date string 结束日期(YYYY-MM-DD格式) Example: 2024-12-31
     * @queryParam limit integer 返回记录数限制，默认50 Example: 50
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "user_id": 1,
     *     "activities": [
     *       {
     *         "id": 1,
     *         "action": "login",
     *         "description": "用户登录",
     *         "ip_address": "192.168.1.1",
     *         "is_success": true,
     *         "created_at": "2024-01-01T08:00:00.000000Z",
     *         "store": {
     *           "id": 1,
     *           "name": "总店"
     *         }
     *       }
     *     ]
     *   }
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function userActivity(Request $request, ?int $userId = null)
    {
        // 非管理员只能查看自己的日志
        if (! $this->isAdmin()) {
            $userId = auth()->id();
        } elseif ($userId === null) {
            $userId = $request->get('user_id', auth()->id());
        }

        $logs = AuditLog::where('user_id', $userId)
            ->dateRange($request->start_date, $request->end_date)
            ->orderBy('created_at', 'desc')
            ->limit($request->get('limit', 50))
            ->get();

        return $this->successResponse([
            'user_id' => $userId,
            'activities' => $logs,
        ]);
    }

    /**
     * 解析模型类型
     */
    protected function resolveModelType(string $type): string
    {
        // 如果是完整类名，直接返回
        if (str_contains($type, '\\')) {
            return $type;
        }

        // 简短名称映射
        $typeMap = [
            'invoice' => 'App\Models\Invoice',
            'payment' => 'App\Models\Payment',
            'customer' => 'App\Models\Customer',
            'store' => 'App\Models\Store',
            'user' => 'App\Models\User',
            'attachment' => 'App\Models\Attachment',
            'payment_allocation' => 'App\Models\PaymentAllocation',
            'payment_discount' => 'App\Models\PaymentDiscount',
            'invoice_item' => 'App\Models\InvoiceItem',
        ];

        return $typeMap[strtolower($type)] ?? 'App\\Models\\'.ucfirst($type);
    }

    /**
     * 获取模型简短名称
     */
    protected function getModelShortName(string $fullClass): string
    {
        return strtolower(class_basename($fullClass));
    }
}
