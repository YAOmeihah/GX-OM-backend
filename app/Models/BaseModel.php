<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base Model
 *
 * 所有业务模型的基类，提供统一的日期序列化格式
 */
abstract class BaseModel extends Model
{
    /**
     * 为数组 / JSON 序列化准备日期格式
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        // 返回 ISO 8601 格式，前端可以直接使用 new Date() 解析
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
