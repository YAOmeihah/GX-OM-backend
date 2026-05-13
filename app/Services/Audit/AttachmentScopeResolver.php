<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * 附件作用域解析器
 *
 * 根据附件关联的对象类型判断日志作用域
 */
class AttachmentScopeResolver
{
    /**
     * 解析附件的作用域类型
     *
     * @param Model $attachment 附件模型实例
     * @return string 'global' 或 'store'
     */
    public function resolve(Model $attachment): string
    {
        $config = config('audit_scope.mixed.models.App\\Models\\Attachment');

        // 获取附件关联的对象类型
        $attachableType = $attachment->attachable_type;

        // 如果关联的是门店业务对象，则为门店日志
        if ($attachableType && in_array($attachableType, $config['store_related'] ?? [])) {
            return 'store';
        }

        // 否则为全局日志
        return 'global';
    }
}
