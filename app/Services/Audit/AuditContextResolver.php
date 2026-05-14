<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 审计上下文解析器
 *
 * 统一处理审计日志的作用域判断逻辑
 */
class AuditContextResolver
{
    /**
     * 解析审计上下文
     *
     * @param  string  $action  操作类型
     * @param  Model|null  $model  操作的模型实例
     * @param  User|null  $operator  操作者（可能为null，如系统任务）
     * @param  Request|null  $request  请求对象
     */
    public function resolve(
        string $action,
        ?Model $model,
        ?User $operator,
        ?Request $request = null
    ): AuditContext {
        $scopeConfig = config('audit_scope');

        // 判断作用域类型
        $scopeType = $this->determineScopeType($action, $model, $scopeConfig);

        // 获取业务门店ID
        $businessStoreId = $this->getBusinessStoreId($model, $scopeType);

        // 获取操作者门店ID
        $actorStoreId = $this->getActorStoreId($operator, $request);

        return new AuditContext(
            scopeType: $scopeType,
            businessStoreId: $businessStoreId,
            actorStoreId: $actorStoreId,
        );
    }

    /**
     * 判断作用域类型
     */
    private function determineScopeType(string $action, ?Model $model, array $config): string
    {
        // 1. 检查是否为全局操作
        if (in_array($action, $config['global']['actions'])) {
            return 'global';
        }

        // 2. 检查是否为全局模型
        if ($model) {
            $modelClass = get_class($model);

            if (in_array($modelClass, $config['global']['models'])) {
                return 'global';
            }

            // 3. 检查是否为混合类型（需要动态判断）
            if (isset($config['mixed']['models'][$modelClass])) {
                return $this->resolveMixedType($model, $config['mixed']['models'][$modelClass]);
            }

            // 4. 检查是否为门店业务模型
            if (in_array($modelClass, $config['store']['models'])) {
                return 'store';
            }
        }

        // 5. 默认为门店业务日志（保守策略）
        return 'store';
    }

    /**
     * 解析混合类型的作用域
     *
     * 例如：Attachment 根据其关联的对象决定作用域
     */
    private function resolveMixedType(Model $model, array $mixedConfig): string
    {
        // 如果配置了自定义解析器，使用解析器
        if (isset($mixedConfig['resolver'])) {
            $resolver = app($mixedConfig['resolver']);

            return $resolver->resolve($model);
        }

        // 默认逻辑：检查关联对象
        if (isset($mixedConfig['store_related'])) {
            // 检查模型是否关联到门店业务对象
            foreach ($mixedConfig['store_related'] as $relatedClass) {
                if ($this->isRelatedTo($model, $relatedClass)) {
                    return 'store';
                }
            }
        }

        // 默认为全局
        return 'global';
    }

    /**
     * 检查模型是否关联到指定类型
     */
    private function isRelatedTo(Model $model, string $relatedClass): bool
    {
        // 检查 attachable_type 字段（多态关联）
        if (isset($model->attachable_type) && $model->attachable_type === $relatedClass) {
            return true;
        }

        // 可以扩展其他关联检查逻辑
        return false;
    }

    /**
     * 获取业务门店ID
     */
    private function getBusinessStoreId(?Model $model, string $scopeType): ?int
    {
        // 全局日志没有业务门店
        if ($scopeType === 'global') {
            return null;
        }

        // 从模型获取门店ID
        if ($model && isset($model->store_id)) {
            return $model->store_id;
        }

        // 如果模型没有 store_id，但作用域是 store，这是异常情况
        // 记录警告日志
        if ($model) {
            \Log::warning('Store scope log without store_id', [
                'model' => get_class($model),
                'id' => $model->id ?? null,
            ]);
        }

        return null;
    }

    /**
     * 获取操作者门店ID
     */
    private function getActorStoreId(?User $operator, ?Request $request): ?int
    {
        // 如果没有操作者（系统任务），返回 null
        if (! $operator) {
            return null;
        }

        // 优先从请求中获取当前门店
        if ($request && $request->has('current_store_id')) {
            return $request->input('current_store_id');
        }

        // 从用户的第一个关联门店获取
        $firstStore = $operator->stores()->first();

        return $firstStore?->id;
    }
}
