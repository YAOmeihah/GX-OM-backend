<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\SystemUpdateRun;
use App\Models\User;
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

    public function test_system_update_run_table_persists_status_and_logs(): void
    {
        $user = User::factory()->create();

        $run = SystemUpdateRun::create([
            'tag' => 'v1.2.3',
            'version' => '1.2.3',
            'status' => 'pending',
            'actor_user_id' => $user->id,
            'metadata' => ['source' => 'github-release'],
            'log_lines' => ['Queued update', 'Downloaded package'],
        ]);

        $run->refresh();

        $this->assertSame('pending', $run->status);
        $this->assertSame('v1.2.3', $run->tag);
        $this->assertSame(['source' => 'github-release'], $run->metadata);
        $this->assertSame(['Queued update', 'Downloaded package'], $run->log_lines);
    }

    public function test_deleting_actor_user_nulls_system_update_run_actor_id(): void
    {
        $user = User::factory()->create();

        $run = SystemUpdateRun::create([
            'tag' => 'v1.2.3',
            'version' => '1.2.3',
            'status' => 'pending',
            'actor_user_id' => $user->id,
            'log_lines' => [],
        ]);

        $user->delete();

        $this->assertNull($run->refresh()->actor_user_id);
    }
}
