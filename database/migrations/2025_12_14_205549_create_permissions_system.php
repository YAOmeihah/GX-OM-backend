<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 创建权限表
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('权限名称');
            $table->string('slug')->unique()->comment('权限标识，如: invoices.create');
            $table->string('module')->comment('所属模块，如: invoices, payments');
            $table->text('description')->nullable()->comment('权限描述');
            $table->timestamps();

            // 索引优化
            $table->index('module');
        });

        // 创建角色-权限关联表
        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // 唯一约束
            $table->unique(['permission_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
    }
};
