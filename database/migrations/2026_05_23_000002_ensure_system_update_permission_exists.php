<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = [
            'name' => '系统更新',
            'module' => 'system',
            'description' => '检查、上传、排队和回滚系统更新',
            'updated_at' => now(),
        ];

        if (
            DB::table('permissions')
                ->where('slug', 'system-updates.manage')
                ->exists()
        ) {
            DB::table('permissions')
                ->where('slug', 'system-updates.manage')
                ->update($permission);

            return;
        }

        DB::table('permissions')->insert([
            ...$permission,
            'slug' => 'system-updates.manage',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('slug', 'system-updates.manage')
            ->delete();
    }
};
