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
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_date');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dateTime('invoice_date')->notNull();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('payment_date')->notNull();
        });
    }
};
