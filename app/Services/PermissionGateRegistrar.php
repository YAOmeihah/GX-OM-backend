<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class PermissionGateRegistrar
{
    public const CACHE_KEY = 'authorization.permission_slugs';

    public function __construct(private readonly CacheRepository $cache) {}

    public function register(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isAdmin()) {
                return true;
            }

            return null;
        });

        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissionSlugs() as $slug) {
            Gate::define($slug, function (User $user) use ($slug) {
                return $user->hasPermission($slug);
            });
        }
    }

    public function forgetCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, string>
     */
    private function permissionSlugs(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, function () {
            return Permission::query()
                ->orderBy('id')
                ->pluck('slug')
                ->all();
        });
    }
}
