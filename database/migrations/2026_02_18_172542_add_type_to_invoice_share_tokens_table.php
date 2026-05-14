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
        Schema::table('invoice_share_tokens', function (Blueprint $table) {
            $table->enum('type', ['fixed', 'dynamic'])->default('fixed')->after('token')->comment('分享类型：fixed-固定快照, dynamic-动态实时');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_share_tokens', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
