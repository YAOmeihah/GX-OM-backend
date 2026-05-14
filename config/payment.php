<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 优惠减免配置
    |--------------------------------------------------------------------------
    |
    | 这里定义了优惠减免相关的配置参数
    |
    */

    // 单笔优惠减免最大金额
    'max_discount_amount' => env('PAYMENT_MAX_DISCOUNT_AMOUNT', 1000),

    // 每日优惠减免总额限制
    'daily_discount_limit' => env('PAYMENT_DAILY_DISCOUNT_LIMIT', 5000),

    // 优惠减免类型配置
    'discount_types' => [
        'write_off' => [
            'name' => '坏账核销',
            'max_amount' => 2000,
            'requires_approval' => true,
            'approval_roles' => ['admin', 'store_owner'],
        ],
        'discount' => [
            'name' => '折扣',
            'max_amount' => 500,
            'requires_approval' => false,
            'approval_roles' => ['admin', 'store_owner', 'store_staff'],
        ],
        'promotion' => [
            'name' => '促销优惠',
            'max_amount' => 1000,
            'requires_approval' => false,
            'approval_roles' => ['admin', 'store_owner'],
        ],
    ],

    // 自动优惠减免配置
    'auto_discount' => [
        'enabled' => env('PAYMENT_AUTO_DISCOUNT_ENABLED', true),
        'max_amount' => env('PAYMENT_AUTO_DISCOUNT_MAX_AMOUNT', 100),
        'threshold' => env('PAYMENT_AUTO_DISCOUNT_THRESHOLD', 10), // 小于此金额自动建议优惠减免
    ],

    // 审计配置
    'audit' => [
        'log_all_discounts' => true,
        'require_reason' => true,
        'min_reason_length' => 5,
    ],
];
