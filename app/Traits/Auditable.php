<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\App;

/**
 * 审计日志特征
 *
 * 在模型中使用此特征可自动记录创建、更新、删除操作
 *
 * 使用方法：
 * class Invoice extends Model {
 *     use Auditable;
 * }
 */
trait Auditable
{
    /**
     * 静态标志位：全局禁用审计日志（不影响模型事件系统）
     */
    protected static bool $auditingDisabled = false;

    /**
     * 实例级暂存：保存前捕获的旧值，供 updated 事件使用
     */
    protected array $auditOldValues = [];

    /**
     * 启动时注册事件监听
     */
    public static function bootAuditable(): void
    {
        // 保存前捕获旧值（此时 getOriginal() 还是真正的旧值）
        static::updating(function ($model) {
            if ($model->shouldAudit('update')) {
                $model->auditOldValues = [];
                foreach (array_keys($model->getDirty()) as $key) {
                    $model->auditOldValues[$key] = $model->getOriginal($key);
                }
            }
        });

        // 创建后记录
        static::created(function ($model) {
            if ($model->shouldAudit('create')) {
                $model->logAudit(AuditLog::ACTION_CREATE);
            }
        });

        // 更新后记录（使用 updating 阶段捕获的旧值）
        static::updated(function ($model) {
            if ($model->shouldAudit('update') && $model->wasChanged()) {
                $model->logAudit(AuditLog::ACTION_UPDATE);
            }
        });

        // 删除后记录
        static::deleted(function ($model) {
            if ($model->shouldAudit('delete')) {
                $model->logAudit(AuditLog::ACTION_DELETE);
            }
        });
    }

    /**
     * 记录审计日志
     */
    protected function logAudit(string $action): void
    {
        try {
            $auditService = App::make(AuditLogService::class);

            switch ($action) {
                case AuditLog::ACTION_CREATE:
                    $auditService->logCreate($this);
                    break;

                case AuditLog::ACTION_UPDATE:
                    $oldValues = $this->getOriginalForAudit();
                    $auditService->logUpdate($this, $oldValues);
                    break;

                case AuditLog::ACTION_DELETE:
                    $auditService->logDelete($this);
                    break;

                default:
                    $auditService->log($action, $this);
            }
        } catch (\Exception $e) {
            // 审计日志失败不应影响主业务
            \Log::error('审计日志记录失败', [
                'action' => $action,
                'model' => get_class($this),
                'model_id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取用于审计的原始值（来自 updating 阶段的暂存）
     */
    protected function getOriginalForAudit(): array
    {
        return $this->auditOldValues;
    }

    /**
     * 判断是否应该记录审计日志
     */
    protected function shouldAudit(string $action): bool
    {
        // 检查静态标志位（由 withoutAuditingDo 设置，不影响 Observer）
        if (static::$auditingDisabled) {
            return false;
        }

        // 检查是否全局禁用
        if (property_exists($this, 'disableAudit') && $this->disableAudit) {
            return false;
        }

        // 检查特定操作是否禁用
        if (property_exists($this, 'auditExclude') && in_array($action, $this->auditExclude)) {
            return false;
        }

        // 检查是否有排除的字段变更（仅更新时）
        if ($action === 'update' && property_exists($this, 'auditExcludeFields')) {
            $changedFields = array_keys($this->getChanges());
            $nonExcludedChanges = array_diff($changedFields, $this->auditExcludeFields);

            // 如果所有变更都是被排除的字段，则不记录
            if (empty($nonExcludedChanges)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 临时禁用审计日志
     */
    public function withoutAudit(): static
    {
        $this->disableAudit = true;

        return $this;
    }

    /**
     * 启用审计日志
     */
    public function withAudit(): static
    {
        $this->disableAudit = false;

        return $this;
    }

    /**
     * 执行不记录审计日志的操作
     *
     * 注意：此方法使用静态标志位禁用 Audit，而不是关闭事件分发器。
     * 这样可以保证 Model Observer（如 InvoiceObserver）仍然正常触发。
     */
    public static function withoutAuditingDo(callable $callback): mixed
    {
        static::$auditingDisabled = true;

        try {
            return $callback();
        } finally {
            static::$auditingDisabled = false;
        }
    }

    /**
     * 获取此模型的审计日志
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * 获取最新的审计日志
     */
    public function latestAuditLog()
    {
        return $this->auditLogs()->latest()->first();
    }

    /**
     * 获取指定操作类型的审计日志
     */
    public function auditLogsForAction(string $action)
    {
        return $this->auditLogs()->where('action', $action)->get();
    }
}
