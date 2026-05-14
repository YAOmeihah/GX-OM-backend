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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->decimal('allocated_amount', 10, 2)->default(0);
            $table->date('payment_date');
            $table->string('payment_method')->default('cash')->comment('cash, bank_transfer, wechat, alipay, other');
            $table->string('reference_number')->nullable()->comment('银行转账号、支付宝交易号等');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
