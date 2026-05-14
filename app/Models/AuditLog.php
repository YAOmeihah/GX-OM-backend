<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'action_label',
        'auditable_type',
        'auditable_id',
        'auditable_label',

        // 作用域信息
        'scope_type',
        'business_store_id',
        'actor_store_id',

        'old_values',
        'new_values',
        'changed_fields',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
        'description',
        'metadata',
        'change_payload',
        'is_success',
        'error_message',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'metadata' => 'array',
        'change_payload' => 'array',
        'is_success' => 'boolean',
    ];

    protected $appends = [
        'business_store_name',
        'actor_store_name',
    ];

    /**
     * 操作类型常量
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_VIEW = 'view';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_ALLOCATE = 'allocate';
    public const ACTION_DISCOUNT = 'discount';
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_EXPORT = 'export';
    public const ACTION_IMPORT = 'import';

    /**
     * 操作类型标签映射
     */
    public const ACTION_LABELS = [
        self::ACTION_CREATE => '创建',
        self::ACTION_UPDATE => '更新',
        self::ACTION_DELETE => '删除',
        self::ACTION_VIEW => '查看',
        self::ACTION_LOGIN => '登录',
        self::ACTION_LOGOUT => '登出',
        self::ACTION_ALLOCATE => '分配还款',
        self::ACTION_DISCOUNT => '优惠减免',
        self::ACTION_UPLOAD => '上传附件',
        self::ACTION_DOWNLOAD => '下载附件',
        self::ACTION_EXPORT => '导出数据',
        self::ACTION_IMPORT => '导入数据',
        'maintenance_execute' => '执行数据维护',
    ];

    /**
     * 字段名中文映射（覆盖所有业务模型的常用字段）
     */
    public const FIELD_LABELS = [
        // 通用
        'id'                => 'ID',
        'created_at'        => '创建时间',
        'updated_at'        => '更新时间',
        'deleted_at'        => '删除时间',
        'customer_id'       => '客户',
        'user_id'           => '操作用户',
        'description'       => '备注',
        'status'            => '状态',
        // Invoice
        'invoice_number'    => '账单编号',
        'amount'            => '账单金额',
        'paid_amount'       => '已付金额',
        'due_date'          => '到期日期',
        'created_by'        => '创建人',
        // Payment
        'payment_number'    => '还款编号',
        'payment_method'    => '还款方式',
        'remaining_amount'  => '剩余金额',
        // Customer
        'name'              => '姓名',
        'phone'             => '手机号',
        'email'             => '邮箱',
        'address'           => '地址',
        // Store
        'code'              => '门店编码',
        'contact_phone'     => '联系电话',
        // User
        'username'          => '用户名',
        'role'              => '角色',
        // PaymentAllocation
        'invoice_id'        => '账单',
        'payment_id'        => '还款',
        'allocated_amount'  => '分配金额',
        // PaymentDiscount
        'discount_amount'   => '减免金额',
        'discount_type'     => '减免类型',
        'reason'            => '减免原因',
        // Attachment
        'original_filename' => '文件名',
        'file_size'         => '文件大小',
        'mime_type'         => '文件类型',
    ];

    /**
     * 枚举字段值中文映射
     * 格式：'字段名.原始值' => '中文'
     */
    public const FIELD_VALUE_LABELS = [
        // Invoice status
        'status.unpaid'         => '未付款',
        'status.partially_paid' => '部分付款',
        'status.paid'           => '已付清',
        'status.overdue'        => '已逾期',
        // Payment method
        'payment_method.cash'         => '现金',
        'payment_method.bank_transfer' => '银行转账',
        'payment_method.wechat'       => '微信支付',
        'payment_method.alipay'       => '支付宝',
        'payment_method.other'        => '其他',
        // PaymentDiscount type
        'discount_type.discount'   => '折扣',
        'discount_type.promotion'  => '促销优惠',
        'discount_type.write_off'  => '坏账核销',
        // Boolean
        'is_success.1'  => '成功',
        'is_success.0'  => '失败',
        'is_success.'   => '-',
    ];

    /**
     * 模型类型标签映射
     */
    public const MODEL_LABELS = [
        'App\Models\Invoice' => '账单',
        'App\Models\Payment' => '还款',
        'App\Models\Customer' => '客户',
        'App\Models\Store' => '门店',
        'App\Models\User' => '用户',
        'App\Models\Attachment' => '附件',
        'App\Models\PaymentAllocation' => '还款分配',
        'App\Models\PaymentDiscount' => '优惠减免',
        'App\Models\InvoiceItem' => '账单明细',
        'maintenance' => '数据维护',
    ];

    /**
     * 获取操作用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 获取业务归属门店（新架构）
     *
     * 仅对 scope_type='store' 的日志有意义
     */
    public function businessStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'business_store_id');
    }

    /**
     * 获取操作者所在门店（新架构）
     */
    public function actorStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'actor_store_id');
    }

    /**
     * 获取操作对象（多态关联）
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 获取操作类型标签
     */
    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * 获取模型类型标签
     */
    public function getModelLabelAttribute(): string
    {
        return self::MODEL_LABELS[$this->auditable_type] ?? class_basename($this->auditable_type);
    }

    /**
     * 获取格式化的变更内容
     * 在原有 field/old/new 基础上附加语义化字段，不破坏现有结构
     */
    public function getFormattedChangesAttribute(): array
    {
        if (empty($this->changed_fields)) {
            return [];
        }

        $changes = [];
        foreach ($this->changed_fields as $field) {
            $oldVal = $this->old_values[$field] ?? null;
            $newVal = $this->new_values[$field] ?? null;

            $changes[] = [
                // 原有字段，保持不变
                'field' => $field,
                'old'   => $oldVal,
                'new'   => $newVal,
                // 新增语义化字段
                'field_label' => self::FIELD_LABELS[$field] ?? $field,
                'old_label'   => $this->resolveValueLabel($field, $oldVal),
                'new_label'   => $this->resolveValueLabel($field, $newVal),
            ];
        }

        return $changes;
    }

    /**
     * 将字段原始值解析为可读标签
     */
    protected function resolveValueLabel(string $field, mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        // 数组/对象类型无法映射枚举标签，直接返回 JSON 字符串
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 布尔值兜底
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        $key = $field . '.' . $value;
        if (isset(self::FIELD_VALUE_LABELS[$key])) {
            return self::FIELD_VALUE_LABELS[$key];
        }

        return (string) $value;
    }

    /**
     * 按用户筛选
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 按业务归属门店筛选（新架构）
     *
     * 只返回门店业务日志（scope_type='store'）且归属指定门店的记录
     */
    public function scopeByBusinessStore($query, int $storeId)
    {
        return $query->where('scope_type', 'store')
            ->where('business_store_id', $storeId);
    }

    /**
     * 按操作类型筛选
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * 按模型类型筛选
     */
    public function scopeByModel($query, string $modelType)
    {
        return $query->where('auditable_type', $modelType);
    }

    /**
     * 按日期范围筛选
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }
        return $query;
    }

    /**
     * 仅成功操作
     */
    public function scopeSuccessful($query)
    {
        return $query->where('is_success', true);
    }

    /**
     * 仅失败操作
     */
    public function scopeFailed($query)
    {
        return $query->where('is_success', false);
    }

    /**
     * 获取业务门店名称（访问器）
     */
    public function getBusinessStoreNameAttribute(): ?string
    {
        return $this->businessStore?->name;
    }

    /**
     * 获取操作者门店名称（访问器）
     */
    public function getActorStoreNameAttribute(): ?string
    {
        return $this->actorStore?->name;
    }

    /**
     * Prepare a date for array / JSON serialization.
     * 使用本地时区格式，避免前端时区转换问题
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
