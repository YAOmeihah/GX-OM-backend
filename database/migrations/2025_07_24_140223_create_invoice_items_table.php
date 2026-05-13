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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->string('item_name')->comment('商品/服务名称');
            $table->text('item_description')->nullable()->comment('商品描述/规格');
            $table->decimal('quantity', 10, 3)->default(1)->comment('数量');
            $table->decimal('unit_price', 10, 2)->comment('单价');
            $table->decimal('subtotal', 10, 2)->comment('小计金额');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();

            // 添加索引
            $table->index('invoice_id');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
