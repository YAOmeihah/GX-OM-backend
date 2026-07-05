<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemUpdatePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_update_permission_is_seeded_for_admin_management(): void
    {
        $this->seed(PermissionSeeder::class);

        $permission = Permission::where('slug', 'system-updates.manage')->first();

        $this->assertNotNull($permission);
        $this->assertSame('system', $permission->module);
    }

    public function test_system_update_permission_exists_after_migrations_without_manual_seeding(): void
    {
        $permission = Permission::where('slug', 'system-updates.manage')->first();

        $this->assertNotNull($permission);
        $this->assertSame('系统更新', $permission->name);
        $this->assertSame('system', $permission->module);
    }
}
