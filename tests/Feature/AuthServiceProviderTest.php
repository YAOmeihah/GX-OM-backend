<?php

namespace Tests\Feature;

use App\Models\Permission;
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
        $this->assertSame(['invoices.view'], Cache::get(PermissionGateRegistrar::CACHE_KEY));

        Permission::create([
            'name' => 'Create invoices',
            'slug' => 'invoices.create',
            'module' => 'invoices',
            'description' => 'Create invoice',
        ]);

        $this->assertNull(Cache::get(PermissionGateRegistrar::CACHE_KEY));
    }
}
