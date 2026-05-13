<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\StorePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Payment::class => PaymentPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Customer::class => CustomerPolicy::class,
        Store::class => StorePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // 管理员拥有所有权限
        Gate::before(function (User $user, string $ability) {
            if ($user->isAdmin()) {
                return true;
            }
        });

        // 动态注册所有权限Gate
        // 这样可以使用 Gate::allows('invoices.view') 或 $this->authorize('invoices.view')
        $permissions = \App\Models\Permission::all();
        foreach ($permissions as $permission) {
            Gate::define($permission->slug, function (User $user) use ($permission) {
                return $user->hasPermission($permission->slug);
            });
        }
    }
}
