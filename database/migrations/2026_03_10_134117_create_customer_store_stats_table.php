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
        Schema::create('customer_store_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            // 计算列
            $table->decimal('total_debt', 10, 2)->default(0.00)->comment('该门店下累计总欠款');
            $table->timestamp('last_transaction_at')->nullable()->comment('最后交易/下单时间');
            $table->timestamps();

            // 核心复合唯一索引：每个客户每个店只有一条记录
            $table->unique(['customer_id', 'store_id'], 'unq_customer_store');

            // 为高速排序添加单独索引
            $table->index('total_debt', 'idx_total_debt');
            $table->index('last_transaction_at', 'idx_last_transaction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_store_stats');
    }
};
