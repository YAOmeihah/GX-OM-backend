<?php

// 临时脚本：生成测试分享 token

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$invoice = \App\Models\Invoice::first();

if (! $invoice) {
    echo "没有找到任何账单\n";
    exit(1);
}

$user = \App\Models\User::first();
if (! $user) {
    echo "没有找到任何用户\n";
    exit(1);
}

$token = \App\Models\InvoiceShareToken::create([
    'token' => \App\Models\InvoiceShareToken::generateToken(),
    'invoice_ids' => [$invoice->id],
    'customer_id' => $invoice->customer_id,
    'store_id' => $invoice->store_id,
    'created_by' => $user->id,
    'expires_at' => now()->addMonths(3),
]);

echo "====================================\n";
echo "测试 Token 生成成功！\n";
echo "====================================\n";
echo 'Token: '.$token->token."\n";
echo '小程序路径: /pages/bill/index?token='.$token->token."\n";
echo '过期时间: '.$token->expires_at."\n";
echo "====================================\n";
