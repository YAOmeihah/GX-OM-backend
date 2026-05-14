<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify invoices table
        // invoice_date: DATE -> DATETIME
        // due_date: DATE -> DATETIME (nullable)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE invoices MODIFY invoice_date DATETIME NOT NULL");
            DB::statement("ALTER TABLE invoices MODIFY due_date DATETIME NULL");

            // Modify payments table
            // payment_date: DATE -> DATETIME
            DB::statement("ALTER TABLE payments MODIFY payment_date DATETIME NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Revert to DATE
            DB::statement("ALTER TABLE invoices MODIFY invoice_date DATE NOT NULL");
            DB::statement("ALTER TABLE invoices MODIFY due_date DATE NULL");
            DB::statement("ALTER TABLE payments MODIFY payment_date DATE NOT NULL");
        }
    }
};
