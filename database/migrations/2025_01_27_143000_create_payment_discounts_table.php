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
        Schema::create('payment_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->decimal('discount_amount', 10, 2)->comment('优惠减免金额');
            $table->enum('discount_type', ['write_off', 'discount', 'promotion'])
                ->default('discount')
                ->comment('减免类型：write_off=坏账核销, discount=折扣, promotion=促销优惠');
            $table->text('reason')->nullable()->comment('减免原因说明');
            $table->foreignId('approved_by')->constrained('users')->comment('审批人');
            $table->timestamps();

            // 添加索引
            $table->index(['payment_id', 'invoice_id']);
            $table->index('approved_by');
            $table->index('discount_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_discounts');
    }
};
