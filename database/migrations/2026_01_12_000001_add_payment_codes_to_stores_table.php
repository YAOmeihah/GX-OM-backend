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
        Schema::table('stores', function (Blueprint $table) {
            $table->text('wechat_pay_code_data')->nullable()->after('is_active')->comment('微信支付二维码解码数据');
            $table->text('alipay_code_data')->nullable()->after('wechat_pay_code_data')->comment('支付宝支付二维码解码数据');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['wechat_pay_code_data', 'alipay_code_data']);
        });
    }
};
