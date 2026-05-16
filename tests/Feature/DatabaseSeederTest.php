<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_initializes_admin_role_and_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->isAdmin());
        $this->assertGreaterThan(0, Permission::count());
        $this->assertGreaterThan(0, $adminRole->permissions()->count());
        $this->assertTrue($admin->hasPermission('users.view'));
    }
}
