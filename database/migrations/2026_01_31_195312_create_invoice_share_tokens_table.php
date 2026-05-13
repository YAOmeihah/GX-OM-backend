<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 分享令牌表
        Schema::create('invoice_share_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique()->comment('唯一分享令牌');
            $table->json('invoice_ids')->comment('关联的账单ID数组');
            $table->foreignId('customer_id')->constrained()->comment('客户ID');
            $table->foreignId('store_id')->constrained()->comment('门店ID');
            $table->foreignId('created_by')->constrained('users')->comment('创建人ID');
            $table->timestamp('expires_at')->comment('过期时间');
            $table->timestamps();

            $table->index('token');
            $table->index('expires_at');
        });

        // 访问日志表
        Schema::create('invoice_share_token_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('invoice_share_tokens')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable()->comment('访问者IP');
            $table->string('user_agent', 500)->nullable()->comment('浏览器/客户端信息');
            $table->timestamp('accessed_at')->useCurrent()->comment('访问时间');

            $table->index('token_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_share_token_logs');
        Schema::dropIfExists('invoice_share_tokens');
    }
};
