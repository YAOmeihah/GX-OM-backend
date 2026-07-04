<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Services\PermissionGateRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_service_provider_boots_when_permissions_table_is_missing(): void
    {
        Schema::dropIfExists('permissions');

        $provider = new AuthServiceProvider($this->app);
        $provider->boot($this->app->make(PermissionGateRegistrar::class));

        $this->assertTrue(true);
    }

    public function test_permission_gate_registrar_caches_permission_slugs_and_forgets_cache_on_permission_change(): void
    {
        Cache::flush();

        Permission::create([
            'name' => 'View invoices',
            'slug' => 'invoices.view',
            'module' => 'invoices',
            'description' => 'View invoice list',
        ]);

        $registrar = $this->app->make(PermissionGateRegistrar::class);
        $registrar->register();

        $this->assertTrue(Gate::has('invoices.view'));
        $cachedPermissionSlugs = Cache::get(PermissionGateRegistrar::CACHE_KEY);

        $this->assertIsArray($cachedPermissionSlugs);
        $this->assertContains('invoices.view', $cachedPermissionSlugs);

        Permission::create([
            'name' => 'Create invoices',
            'slug' => 'invoices.create',
            'module' => 'invoices',
            'description' => 'Create invoice',
        ]);

        $this->assertNull(Cache::get(PermissionGateRegistrar::CACHE_KEY));
    }

    public function test_registered_permission_gates_authorize_users_by_admin_role_and_role_permissions(): void
    {
        Cache::flush();

        $viewInvoices = Permission::create([
            'name' => 'View invoices',
            'slug' => 'invoices.view',
            'module' => 'invoices',
            'description' => 'View invoice list',
        ]);

        $adminRole = Role::create([
            'name' => 'System administrator',
            'slug' => 'admin',
            'description' => 'System administrator',
        ]);

        $invoiceViewerRole = Role::create([
            'name' => 'Invoice viewer',
            'slug' => 'invoice_viewer',
            'description' => 'Can view invoices',
        ]);
        $invoiceViewerRole->permissions()->attach($viewInvoices);

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $viewer = User::factory()->create();
        $viewer->roles()->attach($invoiceViewerRole);

        $unauthorizedUser = User::factory()->create();

        $this->app->make(PermissionGateRegistrar::class)->register();

        $this->assertTrue(Gate::forUser($admin)->allows('invoices.view'));
        $this->assertTrue(Gate::forUser($admin)->allows('invoices.unknown'));
        $this->assertTrue(Gate::forUser($viewer)->allows('invoices.view'));
        $this->assertFalse(Gate::forUser($viewer)->allows('invoices.unknown'));
        $this->assertFalse(Gate::forUser($unauthorizedUser)->allows('invoices.view'));
    }
}
