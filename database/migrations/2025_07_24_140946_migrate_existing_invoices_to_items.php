<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 为现有的账单创建默认明细项
        $invoices = DB::table('invoices')->get();

        foreach ($invoices as $invoice) {
            // 检查是否已经有明细项
            $existingItems = DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->count();

            if ($existingItems == 0) {
                // 创建默认明细项
                DB::table('invoice_items')->insert([
                    'invoice_id' => $invoice->id,
                    'item_name' => '账单项目',
                    'item_description' => $invoice->description,
                    'quantity' => 1,
                    'unit_price' => $invoice->amount,
                    'subtotal' => $invoice->amount,
                    'sort_order' => 0,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 删除所有自动创建的默认明细项
        DB::table('invoice_items')
            ->where('item_name', '账单项目')
            ->where('quantity', 1)
            ->delete();
    }
};
