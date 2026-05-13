<?php

/**
 * 审计日志作用域配置
 *
 * 定义不同类型日志的分类规则：
 * - global: 全局日志（系统级操作，不归属任何门店）
 * - store: 门店业务日志（必须归属某个门店）
 * - mixed: 混合类型（需要根据关联对象动态判断）
 */
return [
    /**
     * 全局日志
     * 这些操作不归属任何门店，只有管理员可见
     */
    'global' => [
        // 全局操作类型
        'actions' => [
            'login',           // 登录
            'logout',          // 登出
            'change_password', // 修改密码
            'view',            // 查看（系统级）
            'export',          // 导出（系统级）
            'import',          // 导入（系统级）
        ],

        // 全局模型类型
        'models' => [
            'App\\Models\\User',           // 用户管理
            'App\\Models\\Role',           // 角色管理
            'App\\Models\\Permission',     // 权限管理
            'App\\Models\\Store',          // 门店管理
        ],
    ],

    /**
     * 门店业务日志
     * 这些操作必须归属某个门店
     */
    'store' => [
        // 门店业务操作类型
        'actions' => [
            'create',    // 创建
            'update',    // 更新
            'delete',    // 删除
            'allocate',  // 分配
            'revoke',    // 撤销
            'discount',  // 优惠减免
        ],

        // 门店业务模型类型
        'models' => [
            'App\\Models\\Invoice',            // 账单
            'App\\Models\\InvoiceItem',        // 账单明细
            'App\\Models\\Payment',            // 还款
            'App\\Models\\PaymentAllocation',  // 还款分配
            'App\\Models\\PaymentDiscount',    // 优惠减免
            'App\\Models\\Customer',           // 客户
        ],
    ],

    /**
     * 混合类型日志
     * 需要根据关联对象动态判断作用域
     */
    'mixed' => [
        'models' => [
            'App\\Models\\Attachment' => [
                // 如果附件关联的是门店业务对象，则为门店日志
                'store_related' => [
                    'App\\Models\\Invoice',
                    'App\\Models\\Payment',
                    'App\\Models\\Customer',
                ],
                // 否则为全局日志
                'resolver' => 'App\\Services\\Audit\\AttachmentScopeResolver',
            ],
        ],
    ],
];
