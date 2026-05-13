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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            
            // 操作用户
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable(); // 冗余存储，防止用户删除后丢失信息
            
            // 操作类型
            $table->string('action', 50); // create, update, delete, view, login, logout, etc.
            $table->string('action_label')->nullable(); // 操作的中文描述
            
            // 操作对象
            $table->string('auditable_type')->nullable(); // 模型类名
            $table->unsignedBigInteger('auditable_id')->nullable(); // 模型ID
            $table->string('auditable_label')->nullable(); // 对象的可读标识，如账单号、客户名
            
            // 关联门店
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_name')->nullable();
            
            // 变更数据
            $table->json('old_values')->nullable(); // 变更前的数据
            $table->json('new_values')->nullable(); // 变更后的数据
            $table->json('changed_fields')->nullable(); // 变更的字段列表
            
            // 请求信息
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_url')->nullable();
            
            // 额外信息
            $table->text('description')->nullable(); // 操作描述
            $table->json('metadata')->nullable(); // 其他元数据
            
            // 操作结果
            $table->boolean('is_success')->default(true);
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // 索引
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
            $table->index('store_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

