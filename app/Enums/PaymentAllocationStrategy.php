<?php

namespace App\Enums;

enum PaymentAllocationStrategy: string
{
    case OLDEST_FIRST = 'oldest_first';
    case DUE_DATE_FIRST = 'due_date_first';
    case SMALLEST_FIRST = 'smallest_first';
    case LARGEST_FIRST = 'largest_first';
    case OVERDUE_FIRST = 'overdue_first';
    case MANUAL = 'manual';

    /**
     * 获取策略的中文描述
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::OLDEST_FIRST => '按账单日期优先（最早的优先）',
            self::DUE_DATE_FIRST => '按到期日期优先（最早到期的优先）',
            self::SMALLEST_FIRST => '按金额从小到大（小额优先）',
            self::LARGEST_FIRST => '按金额从大到小（大额优先）',
            self::OVERDUE_FIRST => '逾期账单优先',
            self::MANUAL => '手动分配',
        };
    }

    /**
     * 获取排序字段和方向
     */
    public function getOrderBy(): array
    {
        return match ($this) {
            self::OLDEST_FIRST => ['created_at', 'asc'],
            self::DUE_DATE_FIRST => ['due_date', 'asc'],
            self::SMALLEST_FIRST => ['amount', 'asc'],
            self::LARGEST_FIRST => ['amount', 'desc'],
            self::OVERDUE_FIRST => ['due_date', 'asc'], // 逾期的按到期日排序
            self::MANUAL => ['id', 'asc'], // 手动模式默认按ID排序
        };
    }

    /**
     * 获取额外的查询条件
     */
    public function getWhereConditions(): array
    {
        return match ($this) {
            self::OVERDUE_FIRST => [
                ['due_date', '<', now()->toDateString()],
                ['status', 'in', ['unpaid', 'partially_paid', 'overdue']],
            ],
            default => [
                ['status', 'in', ['unpaid', 'partially_paid', 'overdue']],
            ],
        };
    }

    /**
     * 获取所有可用的分配策略
     */
    public static function getAvailableStrategies(): array
    {
        return [
            self::OLDEST_FIRST,
            self::DUE_DATE_FIRST,
            self::SMALLEST_FIRST,
            self::LARGEST_FIRST,
            self::OVERDUE_FIRST,
        ];
    }

    /**
     * 从字符串创建策略实例
     */
    public static function fromString(string $strategy): self
    {
        return self::tryFrom($strategy) ?? self::OLDEST_FIRST;
    }
}
