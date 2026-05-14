<?php

namespace App\Services\Audit;

/**
 * 审计上下文
 *
 * 封装审计日志的作用域信息
 */
class AuditContext
{
    public function __construct(
        public readonly string $scopeType,
        public readonly ?int $businessStoreId,
        public readonly ?int $actorStoreId,
    ) {}

    /**
     * 创建全局日志上下文
     */
    public static function global(?int $actorStoreId = null): self
    {
        return new self(
            scopeType: 'global',
            businessStoreId: null,
            actorStoreId: $actorStoreId,
        );
    }

    /**
     * 创建门店业务日志上下文
     */
    public static function store(int $businessStoreId, ?int $actorStoreId = null): self
    {
        return new self(
            scopeType: 'store',
            businessStoreId: $businessStoreId,
            actorStoreId: $actorStoreId ?? $businessStoreId,
        );
    }

    /**
     * 是否为全局日志
     */
    public function isGlobal(): bool
    {
        return $this->scopeType === 'global';
    }

    /**
     * 是否为门店日志
     */
    public function isStore(): bool
    {
        return $this->scopeType === 'store';
    }

    /**
     * 转换为数组（用于数据库写入）
     */
    public function toArray(): array
    {
        return [
            'scope_type' => $this->scopeType,
            'business_store_id' => $this->businessStoreId,
            'actor_store_id' => $this->actorStoreId,
        ];
    }
}
