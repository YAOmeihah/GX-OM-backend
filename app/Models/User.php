<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, Auditable;

    /**
     * 审计日志排除的字段
     */
    protected array $auditExcludeFields = ['updated_at', 'remember_token'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * 获取用户的角色
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * 获取用户所属的门店
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class)->withTimestamps();
    }



    /**
     * 获取用户创建的账单
     */
    public function createdInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    /**
     * 获取用户接收的还款
     */
    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    /**
     * 检查用户是否有指定角色
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * 检查用户是否是系统管理员
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * 检查用户是否属于指定门店
     */
    public function belongsToStore(int $storeId): bool
    {
        return $this->stores()->where('store_id', $storeId)->exists();
    }

    /**
     * 检查用户是否是指定门店的管理员
     */
    public function isManagerOfStore(int $storeId): bool
    {
        return $this->isAdmin() ||
               ($this->hasRole('store_owner') && $this->belongsToStore($storeId));
    }

    /**
     * 检查用户是否是店长
     */
    public function isStoreOwner(): bool
    {
        return $this->hasRole('store_owner');
    }

    /**
     * 检查用户是否是店员
     */
    public function isStoreStaff(): bool
    {
        return $this->hasRole('store_staff');
    }

    // ========== 新增权限系统方法（向后兼容） ==========

    /**
     * 获取用户所有权限（通过角色）
     */
    public function permissions()
    {
        return $this->roles->flatMap->permissions->unique('id');
    }

    /**
     * 检查用户是否有某个权限
     */
    public function hasPermission(string $permission): bool
    {
        // 管理员拥有所有权限
        if ($this->isAdmin()) {
            return true;
        }

        return $this->permissions()->contains('slug', $permission);
    }

    /**
     * 检查是否有任一权限
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否有全部权限
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查是否有任一角色
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    /**
     * 获取用户权限列表（用于前端）
     */
    public function getPermissionsList(): array
    {
        if ($this->isAdmin()) {
            return Permission::pluck('slug')->toArray();
        }

        return $this->permissions()->pluck('slug')->toArray();
    }

    /**
     * 获取用户角色列表（用于前端）
     */
    public function getRolesList(): array
    {
        return $this->roles->pluck('slug')->toArray();
    }
}
