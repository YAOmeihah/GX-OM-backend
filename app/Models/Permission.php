<?php

namespace App\Models;

use App\Services\PermissionGateRegistrar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'module',
        'description',
    ];

    /**
     * 获取拥有此权限的角色
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    protected static function booted(): void
    {
        $forgetPermissionGateCache = function (): void {
            app(PermissionGateRegistrar::class)->forgetCache();
        };

        static::saved($forgetPermissionGateCache);
        static::deleted($forgetPermissionGateCache);
    }

    /**
     * 按模块分组获取权限
     */
    public static function groupedByModule(): array
    {
        return static::all()->groupBy('module')->toArray();
    }

    /**
     * 获取指定模块的权限
     */
    public static function byModule(string $module)
    {
        return static::where('module', $module)->get();
    }
}
