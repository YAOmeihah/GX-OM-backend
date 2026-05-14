<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditContextResolver;
use App\Services\Audit\InvoiceAuditDiffBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    protected AuditContextResolver $contextResolver;

    /**
     * 敏感字段，不记录原始值
     */
    protected array $sensitiveFields = [
        'password',
        'password_hash',
        'remember_token',
        'api_token',
        'secret',
        'secret_key',
        'access_key',
    ];

    public function __construct(AuditContextResolver $contextResolver)
    {
        $this->contextResolver = $contextResolver;
    }

    /**
     * 记录审计日志
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?array $metadata = null,
        bool $isSuccess = true,
        ?string $errorMessage = null,
        ?array $changePayload = null
    ): AuditLog {
        $user = Auth::user();

        // 使用上下文解析器判断作用域
        $context = $this->contextResolver->resolve(
            $action,
            $model,
            $user,
            Request::instance()
        );

        // 过滤敏感字段
        $oldValues = $this->filterSensitiveData($oldValues);
        $newValues = $this->filterSensitiveData($newValues);

        // 计算变更的字段
        $changedFields = $this->getChangedFields($oldValues, $newValues);

        // 获取模型的可读标识
        $auditableLabel = $this->getAuditableLabel($model);

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'action_label' => AuditLog::ACTION_LABELS[$action] ?? $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id' => $model?->getKey(),
            'auditable_label' => $auditableLabel,

            // 作用域信息
            'scope_type' => $context->scopeType,
            'business_store_id' => $context->businessStoreId,
            'actor_store_id' => $context->actorStoreId,

            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'request_method' => Request::method(),
            'request_url' => Request::fullUrl(),
            'description' => $description,
            'metadata' => $metadata,
            'change_payload' => $changePayload,
            'is_success' => $isSuccess,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * 记录创建操作
     */
    public function logCreate(Model $model, ?string $description = null): AuditLog
    {
        $newValues = $model->toArray();
        $changePayload = null;

        // 账单创建走结构化 diff 路径
        if ($model instanceof \App\Models\Invoice) {
            $model->load('items');  // 确保加载明细
            $newValues = $model->toArray();

            $builder = new InvoiceAuditDiffBuilder;
            $changePayload = $builder->buildForCreate(
                $newValues,
                $model->invoice_number,
                $model->customer_id
            );

            // description 降级为短摘要
            if ($description === null) {
                $description = $changePayload['summary']['subtitle'] ?? $this->generateDescription(AuditLog::ACTION_CREATE, $model, null, $newValues);
            }
        } else {
            $description = $description ?? $this->generateDescription(AuditLog::ACTION_CREATE, $model, null, $newValues);
        }

        return $this->log(
            AuditLog::ACTION_CREATE,
            $model,
            null,
            $newValues,
            $description,
            null,
            true,
            null,
            $changePayload
        );
    }

    /**
     * 记录更新操作
     */
    public function logUpdate(Model $model, array $oldValues, ?string $description = null, ?array $newValues = null): AuditLog
    {
        $newValues = $newValues ?? $model->toArray();

        $changePayload = null;

        // 账单更新走结构化 diff 路径
        if ($model instanceof \App\Models\Invoice) {
            $builder = new InvoiceAuditDiffBuilder;
            $changePayload = $builder->build(
                $oldValues,
                $newValues,
                $model->invoice_number,
                $model->customer_id
            );

            // description 降级为短摘要
            if ($description === null) {
                $description = $changePayload['summary']['subtitle'] ?? $this->generateDescription(AuditLog::ACTION_UPDATE, $model, $oldValues, $newValues);
            }
        } else {
            $description = $description ?? $this->generateDescription(AuditLog::ACTION_UPDATE, $model, $oldValues, $newValues);
        }

        return $this->log(
            AuditLog::ACTION_UPDATE,
            $model,
            $oldValues,
            $newValues,
            $description,
            null,
            true,
            null,
            $changePayload
        );
    }

    /**
     * 记录删除操作
     */
    public function logDelete(Model $model, ?string $description = null): AuditLog
    {
        $oldValues = $model->toArray();
        $changePayload = null;

        // 账单删除走结构化 diff 路径
        if ($model instanceof \App\Models\Invoice) {
            // 确保加载明细关系
            if (! $model->relationLoaded('items')) {
                $model->load('items');
            }

            $oldValues = $model->toArray();

            // 调试日志：检查 items 是否正确加载
            \Log::debug('Invoice delete - items count', [
                'invoice_id' => $model->id,
                'items_in_array' => count($oldValues['items'] ?? []),
                'items_relation' => $model->items->count(),
            ]);

            $builder = new InvoiceAuditDiffBuilder;
            $changePayload = $builder->buildForDelete(
                $oldValues,
                $model->invoice_number,
                $model->customer_id
            );

            // description 降级为短摘要
            if ($description === null) {
                $description = $changePayload['summary']['subtitle'] ?? $this->generateDescription(AuditLog::ACTION_DELETE, $model, $oldValues, null);
            }
        } else {
            $description = $description ?? $this->generateDescription(AuditLog::ACTION_DELETE, $model, $oldValues, null);
        }

        return $this->log(
            AuditLog::ACTION_DELETE,
            $model,
            $oldValues,
            null,
            $description,
            null,
            true,
            null,
            $changePayload
        );
    }

    /**
     * 记录登录操作
     */
    public function logLogin(User $user, bool $isSuccess = true, ?string $errorMessage = null): AuditLog
    {
        return $this->log(
            AuditLog::ACTION_LOGIN,
            $user,
            null,
            null,
            $isSuccess ? "用户 {$user->name} 登录成功" : "用户 {$user->name} 登录失败",
            null,
            $isSuccess,
            $errorMessage
        );
    }

    /**
     * 记录登出操作
     */
    public function logLogout(?User $user = null): AuditLog
    {
        $user = $user ?? Auth::user();

        return $this->log(
            AuditLog::ACTION_LOGOUT,
            $user,
            null,
            null,
            "用户 {$user?->name} 登出系统"
        );
    }

    /**
     * 记录还款分配操作
     */
    public function logAllocate(Model $payment, Model $invoice, float $amount, ?string $description = null): AuditLog
    {
        return $this->log(
            AuditLog::ACTION_ALLOCATE,
            $payment,
            null,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number ?? null,
                'amount' => $amount,
            ],
            $description ?? "还款 {$payment->payment_number} 分配 {$amount} 元到账单 {$invoice->invoice_number}",
            [
                'invoice_id' => $invoice->id,
                'allocation_amount' => $amount,
            ]
        );
    }

    /**
     * 记录优惠减免操作
     */
    public function logDiscount(Model $payment, Model $invoice, float $amount, string $type, ?string $reason = null): AuditLog
    {
        $typeLabels = [
            'discount' => '折扣',
            'promotion' => '促销优惠',
            'write_off' => '坏账核销',
        ];

        return $this->log(
            AuditLog::ACTION_DISCOUNT,
            $payment,
            null,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number ?? null,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
            ],
            "对账单 {$invoice->invoice_number} 应用{$typeLabels[$type]}，金额 {$amount} 元",
            [
                'invoice_id' => $invoice->id,
                'discount_amount' => $amount,
                'discount_type' => $type,
            ]
        );
    }

    /**
     * 记录附件上传
     */
    public function logUpload(Model $attachment, Model $attachable, ?string $description = null): AuditLog
    {
        return $this->log(
            AuditLog::ACTION_UPLOAD,
            $attachment,
            null,
            [
                'filename' => $attachment->original_filename ?? null,
                'file_size' => $attachment->file_size ?? null,
                'attachable_type' => get_class($attachable),
                'attachable_id' => $attachable->getKey(),
            ],
            $description ?? "上传附件 {$attachment->original_filename}"
        );
    }

    /**
     * 记录自定义操作
     */
    public function logCustom(
        string $action,
        ?Model $model = null,
        ?string $description = null,
        ?array $metadata = null
    ): AuditLog {
        return $this->log($action, $model, null, null, $description, $metadata);
    }

    /**
     * 记录失败操作
     */
    public function logFailure(
        string $action,
        ?Model $model = null,
        string $errorMessage = '',
        ?array $metadata = null
    ): AuditLog {
        return $this->log(
            $action,
            $model,
            null,
            null,
            "操作失败: {$errorMessage}",
            $metadata,
            false,
            $errorMessage
        );
    }

    /**
     * 过滤敏感数据
     */
    protected function filterSensitiveData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($this->sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[已隐藏]';
            }
        }

        return $data;
    }

    /**
     * 获取变更的字段列表
     */
    protected function getChangedFields(?array $oldValues, ?array $newValues): ?array
    {
        if ($oldValues === null || $newValues === null) {
            return null;
        }

        $changedFields = [];
        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($allKeys as $key) {
            $oldValue = $oldValues[$key] ?? null;
            $newValue = $newValues[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changedFields[] = $key;
            }
        }

        return empty($changedFields) ? null : $changedFields;
    }

    /**
     * 获取模型的可读标识
     */
    protected function getAuditableLabel(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        // 按优先级尝试获取标识字段
        $labelFields = ['invoice_number', 'payment_number', 'name', 'title', 'code', 'username', 'email'];

        foreach ($labelFields as $field) {
            if ($model->getAttribute($field)) {
                return $model->getAttribute($field);
            }
        }

        return "#{$model->getKey()}";
    }

    /**
     * 生成语义化描述（按模型类型和操作分发）
     */
    protected function generateDescription(string $action, Model $model, ?array $oldValues = null, ?array $newValues = null): string
    {
        $actor = Auth::user()?->name ?? '系统';

        return match (get_class($model)) {
            \App\Models\Invoice::class => $this->describeInvoice($action, $model, $actor, $oldValues, $newValues),
            \App\Models\Payment::class => $this->describePayment($action, $model, $actor),
            \App\Models\Customer::class => $this->describeCustomer($action, $model, $actor),
            \App\Models\Store::class => $this->describeStore($action, $model, $actor),
            \App\Models\User::class => $this->describeUser($action, $model, $actor),
            \App\Models\PaymentAllocation::class => $this->describeAllocation($action, $model, $actor),
            \App\Models\PaymentDiscount::class => $this->describeDiscount($action, $model, $actor),
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeInvoice(string $action, Model $model, string $actor, ?array $oldValues = null, ?array $newValues = null): string
    {
        $customer = $this->getCustomerName($model);
        $amount = number_format((float) $model->getAttribute('amount'), 2);
        $no = $model->getAttribute('invoice_number') ?? "#{$model->getKey()}";

        $baseDescription = match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 给客户 {$customer} 创建了一笔 ¥{$amount} 的账单（{$no}）",
            AuditLog::ACTION_UPDATE => "{$actor} 修改了客户 {$customer} 的账单 {$no}（¥{$amount}）",
            AuditLog::ACTION_DELETE => "{$actor} 删除了客户 {$customer} 的账单 {$no}（¥{$amount}）",
            default => $this->describeGeneric($action, $model, $actor),
        };

        // 对于更新操作，分析明细项变化
        if ($action === AuditLog::ACTION_UPDATE && $oldValues && $newValues) {
            $itemsChanges = $this->analyzeItemsChanges($oldValues, $newValues);
            if (! empty($itemsChanges)) {
                $baseDescription .= '，'.implode('；', $itemsChanges);
            }
        }

        return $baseDescription;
    }

    private function describePayment(string $action, Model $model, string $actor): string
    {
        $customer = $this->getCustomerName($model);
        $amount = number_format((float) $model->getAttribute('amount'), 2);
        $no = $model->getAttribute('payment_number') ?? "#{$model->getKey()}";

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 为客户 {$customer} 录入了一笔 ¥{$amount} 的还款（{$no}）",
            AuditLog::ACTION_UPDATE => "{$actor} 修改了客户 {$customer} 的还款记录 {$no}（¥{$amount}）",
            AuditLog::ACTION_DELETE => "{$actor} 删除了客户 {$customer} 的还款记录 {$no}（¥{$amount}）",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeCustomer(string $action, Model $model, string $actor): string
    {
        $name = $model->getAttribute('name') ?? "#{$model->getKey()}";

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 新增了客户 {$name}",
            AuditLog::ACTION_UPDATE => "{$actor} 修改了客户 {$name} 的信息",
            AuditLog::ACTION_DELETE => "{$actor} 删除了客户 {$name}",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeStore(string $action, Model $model, string $actor): string
    {
        $name = $model->getAttribute('name') ?? "#{$model->getKey()}";

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 创建了门店 {$name}",
            AuditLog::ACTION_UPDATE => "{$actor} 修改了门店 {$name} 的信息",
            AuditLog::ACTION_DELETE => "{$actor} 删除了门店 {$name}",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeUser(string $action, Model $model, string $actor): string
    {
        $name = $model->getAttribute('name') ?? $model->getAttribute('username') ?? "#{$model->getKey()}";

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 创建了用户 {$name}",
            AuditLog::ACTION_UPDATE => "{$actor} 修改了用户 {$name} 的信息",
            AuditLog::ACTION_DELETE => "{$actor} 删除了用户 {$name}",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeAllocation(string $action, Model $model, string $actor): string
    {
        $amount = number_format((float) $model->getAttribute('allocated_amount'), 2);
        $invoiceId = $model->getAttribute('invoice_id');
        $paymentId = $model->getAttribute('payment_id');

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 将还款#{$paymentId} 的 ¥{$amount} 分配至账单#{$invoiceId}",
            AuditLog::ACTION_DELETE => "{$actor} 撤销了还款#{$paymentId} 对账单#{$invoiceId} 的 ¥{$amount} 分配",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeDiscount(string $action, Model $model, string $actor): string
    {
        $amount = number_format((float) $model->getAttribute('discount_amount'), 2);
        $invoiceId = $model->getAttribute('invoice_id');

        return match ($action) {
            AuditLog::ACTION_CREATE => "{$actor} 对账单#{$invoiceId} 应用了 ¥{$amount} 的优惠减免",
            AuditLog::ACTION_DELETE => "{$actor} 撤销了账单#{$invoiceId} 的 ¥{$amount} 优惠减免",
            default => $this->describeGeneric($action, $model, $actor),
        };
    }

    private function describeGeneric(string $action, Model $model, string $actor): string
    {
        $modelLabel = AuditLog::MODEL_LABELS[get_class($model)] ?? class_basename($model);
        $actionLabel = AuditLog::ACTION_LABELS[$action] ?? $action;
        $auditableLabel = $this->getAuditableLabel($model);

        return "{$actor} {$actionLabel}了{$modelLabel} {$auditableLabel}";
    }

    /**
     * 分析账单明细项的变化（基于内容特征匹配）
     *
     * 注意：由于 Controller 采用"删除所有+重新创建"的策略，
     * 明细的 ID 会变化，因此使用内容特征（名称+数量+单价）来匹配
     */
    private function analyzeItemsChanges(array $oldValues, array $newValues): array
    {
        $changes = [];

        $oldItems = $oldValues['items'] ?? [];
        $newItems = $newValues['items'] ?? [];

        // 如果没有 items 字段或者都为空，直接返回
        if (empty($oldItems) && empty($newItems)) {
            return $changes;
        }

        // 生成明细的内容指纹（用于匹配）
        $generateFingerprint = function ($item) {
            $name = $item['item_name'] ?? '';
            $qty = $item['quantity'] ?? 0;
            $price = (float) ($item['unit_price'] ?? 0);

            // 使用名称+数量+单价作为唯一标识
            return $name.'|'.$qty.'|'.number_format($price, 2);
        };

        // 构建旧明细的指纹映射（指纹 => [明细数据, 已匹配标记]）
        $oldItemsMap = [];
        foreach ($oldItems as $item) {
            $fingerprint = $generateFingerprint($item);
            if (! isset($oldItemsMap[$fingerprint])) {
                $oldItemsMap[$fingerprint] = [];
            }
            $oldItemsMap[$fingerprint][] = ['item' => $item, 'matched' => false];
        }

        // 构建新明细的指纹映射
        $newItemsMap = [];
        foreach ($newItems as $item) {
            $fingerprint = $generateFingerprint($item);
            if (! isset($newItemsMap[$fingerprint])) {
                $newItemsMap[$fingerprint] = [];
            }
            $newItemsMap[$fingerprint][] = ['item' => $item, 'matched' => false];
        }

        // 1. 找出匹配的明细（内容完全相同）
        foreach ($oldItemsMap as $fingerprint => $oldItemsList) {
            if (isset($newItemsMap[$fingerprint])) {
                $newItemsList = $newItemsMap[$fingerprint];

                // 标记匹配的明细（按数量一一对应）
                $matchCount = min(count($oldItemsList), count($newItemsList));
                for ($i = 0; $i < $matchCount; $i++) {
                    $oldItemsMap[$fingerprint][$i]['matched'] = true;
                    $newItemsMap[$fingerprint][$i]['matched'] = true;
                }
            }
        }

        // 2. 统计删除的明细（旧数据中未匹配的）
        foreach ($oldItemsMap as $fingerprint => $oldItemsList) {
            foreach ($oldItemsList as $entry) {
                if (! $entry['matched']) {
                    $item = $entry['item'];
                    $itemName = $item['item_name'] ?? '未知商品';
                    $qty = $item['quantity'] ?? 1;
                    $price = number_format((float) ($item['unit_price'] ?? 0), 2);
                    $changes[] = "删除了明细「{$itemName}」（数量：{$qty}，单价：¥{$price}）";
                }
            }
        }

        // 3. 统计新增的明细（新数据中未匹配的）
        foreach ($newItemsMap as $fingerprint => $newItemsList) {
            foreach ($newItemsList as $entry) {
                if (! $entry['matched']) {
                    $item = $entry['item'];
                    $itemName = $item['item_name'] ?? '未知商品';
                    $qty = $item['quantity'] ?? 1;
                    $price = number_format((float) ($item['unit_price'] ?? 0), 2);
                    $changes[] = "新增了明细「{$itemName}」（数量：{$qty}，单价：¥{$price}）";
                }
            }
        }

        // 注意：由于使用内容指纹匹配，无法检测"修改"操作
        // 因为修改后的明细会被识别为"删除旧的+新增新的"

        return $changes;
    }

    /**
     * 获取模型关联的客户名称
     */
    private function getCustomerName(Model $model): string
    {
        // 优先从已加载关联取
        if ($model->relationLoaded('customer') && $model->customer) {
            return $model->customer->name;
        }

        $customerId = $model->getAttribute('customer_id');
        if (! $customerId) {
            return '未知客户';
        }

        if (! isset($this->customerCache[$customerId])) {
            $customer = \App\Models\Customer::find($customerId);
            $this->customerCache[$customerId] = $customer?->name ?? "客户#{$customerId}";
        }

        return $this->customerCache[$customerId];
    }

    /**
     * 请求级门店名称缓存（实例变量，不跨请求/任务泄漏）
     */
    protected array $storeCache = [];

    /**
     * 请求级客户名称缓存
     */
    protected array $customerCache = [];

    /**
     * 获取门店名称
     */
    protected function getStoreName(int $storeId): ?string
    {
        if (! isset($this->storeCache[$storeId])) {
            $store = Store::find($storeId);
            $this->storeCache[$storeId] = $store?->name;
        }

        return $this->storeCache[$storeId];
    }

    /**
     * 获取审计日志统计
     */
    public function getStatistics(?int $storeId = null, ?string $startDate = null, ?string $endDate = null, string $viewScope = 'all'): array
    {
        $query = AuditLog::query();

        // 根据 view_scope 过滤
        if ($viewScope === 'store') {
            $query->where('scope_type', 'store');
            if ($storeId) {
                $query->byBusinessStore($storeId);
            }
        } elseif ($viewScope === 'global') {
            $query->where('scope_type', 'global');
        }
        // viewScope === 'all' 时不过滤 scope_type

        if ($startDate || $endDate) {
            $query->dateRange($startDate, $endDate);
        }

        $total = $query->count();
        $byAction = (clone $query)->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        $byModel = (clone $query)->selectRaw('auditable_type, COUNT(*) as count')
            ->whereNotNull('auditable_type')
            ->groupBy('auditable_type')
            ->pluck('count', 'auditable_type')
            ->toArray();

        $successRate = $total > 0
            ? round((clone $query)->successful()->count() / $total * 100, 2)
            : 0;

        return [
            'total' => $total,
            'by_action' => $byAction,
            'by_model' => $byModel,
            'success_rate' => $successRate,
        ];
    }
}
