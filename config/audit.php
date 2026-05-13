<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 审计日志配置
    |--------------------------------------------------------------------------
    |
    | 此配置文件用于控制审计日志系统的行为
    |
    */

    /**
     * 是否启用审计日志
     */
    'enabled' => env('AUDIT_ENABLED', true),

    /**
     * 敏感字段列表，这些字段的值不会被记录到日志中
     */
    'sensitive_fields' => [
        'password',
        'password_hash',
        'remember_token',
        'api_token',
        'secret',
        'secret_key',
        'access_key',
        'credit_card',
        'id_card',
    ],

    /**
     * 日志保留天数，超过此天数的日志将被自动清理
     * 设置为 0 表示永不清理
     */
    'retention_days' => env('AUDIT_RETENTION_DAYS', 90),

    /**
     * 需要记录审计日志的模型
     */
    'auditable_models' => [
        'App\Models\Invoice',
        'App\Models\Payment',
        'App\Models\Customer',
        'App\Models\Store',
        'App\Models\User',
        'App\Models\Attachment',
        'App\Models\PaymentAllocation',
        'App\Models\PaymentDiscount',
        'App\Models\InvoiceItem',
    ],

    /**
     * 排除审计的操作
     */
    'exclude_actions' => [
        // 'view',
    ],

    /**
     * 是否记录查看操作
     */
    'log_views' => env('AUDIT_LOG_VIEWS', false),

    /**
     * 每页最大显示数量
     */
    'per_page_max' => 100,

    /**
     * 默认每页数量
     */
    'per_page_default' => 15,

];

