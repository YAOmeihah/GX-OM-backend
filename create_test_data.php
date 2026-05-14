<?php

// 创建完整测试数据：门店、客户、多个账单（含明细和附件）

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "开始创建测试数据...\n";

DB::beginTransaction();
try {
    // 1. 获取现有门店
    $store = \App\Models\Store::first();
    if (! $store) {
        throw new Exception('没有找到门店，请先创建门店');
    }
    // 更新门店信息用于展示
    $store->update(['phone' => '13800138000']);
    echo "门店: {$store->name} (ID: {$store->id})\n";

    // 2. 获取现有客户
    $customer = \App\Models\Customer::first();
    if (! $customer) {
        throw new Exception('没有找到客户，请先创建客户');
    }
    echo "客户: {$customer->name} (ID: {$customer->id})\n";

    // 3. 获取用户
    $user = \App\Models\User::first();
    if (! $user) {
        throw new Exception('没有找到用户，请先创建用户');
    }

    // 4. 创建多个账单
    $invoiceData = [
        [
            'invoice_number' => 'GX-'.date('Ymd').'-001',
            'amount' => 1580.00,
            'paid_amount' => 500.00,
            'status' => 'partially_paid',
            'description' => '婚庆鲜花订单',
            'items' => [
                ['item_name' => '红玫瑰', 'item_description' => '厄瓜多尔进口', 'quantity' => 99, 'unit_price' => 8.00],
                ['item_name' => '白百合', 'item_description' => '云南昆明产', 'quantity' => 20, 'unit_price' => 15.00],
                ['item_name' => '满天星', 'item_description' => '配花', 'quantity' => 10, 'unit_price' => 5.00],
                ['item_name' => '包装费', 'item_description' => '高端礼盒', 'quantity' => 1, 'unit_price' => 138.00],
            ],
        ],
        [
            'invoice_number' => 'GX-'.date('Ymd').'-002',
            'amount' => 2350.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'description' => '开业花篮订单',
            'items' => [
                ['item_name' => '豪华花篮', 'item_description' => '1.8米三层', 'quantity' => 2, 'unit_price' => 680.00],
                ['item_name' => '普通花篮', 'item_description' => '1.2米双层', 'quantity' => 3, 'unit_price' => 280.00],
                ['item_name' => '配送费', 'item_description' => '市区配送', 'quantity' => 1, 'unit_price' => 150.00],
            ],
        ],
        [
            'invoice_number' => 'GX-'.date('Ymd').'-003',
            'amount' => 860.00,
            'paid_amount' => 860.00,
            'status' => 'paid',
            'description' => '日常鲜花补货',
            'items' => [
                ['item_name' => '康乃馨', 'item_description' => '混色', 'quantity' => 50, 'unit_price' => 3.00],
                ['item_name' => '向日葵', 'item_description' => '大头', 'quantity' => 30, 'unit_price' => 8.00],
                ['item_name' => '雏菊', 'item_description' => '白色', 'quantity' => 40, 'unit_price' => 2.50],
                ['item_name' => '尤加利叶', 'item_description' => '进口', 'quantity' => 20, 'unit_price' => 6.00],
            ],
        ],
    ];

    $invoiceIds = [];
    foreach ($invoiceData as $data) {
        $items = $data['items'];
        unset($data['items']);

        // 创建账单
        $invoice = \App\Models\Invoice::create(array_merge($data, [
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'due_date' => now()->addDays(30),
        ]));

        echo "账单: {$invoice->invoice_number} - ¥{$invoice->amount} ({$invoice->status})\n";

        // 创建明细
        $sortOrder = 0;
        foreach ($items as $item) {
            \App\Models\InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_name' => $item['item_name'],
                'item_description' => $item['item_description'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['quantity'] * $item['unit_price'],
                'sort_order' => $sortOrder++,
            ]);
        }
        echo '  - 明细: '.count($items)." 项\n";

        // 创建模拟附件（使用占位图片路径）
        $attachmentPaths = [
            'invoices/'.$invoice->id.'/receipt_'.uniqid().'.jpg',
            'invoices/'.$invoice->id.'/photo_'.uniqid().'.jpg',
        ];
        foreach ($attachmentPaths as $path) {
            \App\Models\Attachment::create([
                'attachable_type' => \App\Models\Invoice::class,
                'attachable_id' => $invoice->id,
                'original_filename' => basename($path),
                'stored_filename' => basename($path),
                'file_path' => $path,
                'file_size' => rand(50000, 200000),
                'mime_type' => 'image/jpeg',
                'uploaded_by' => $user->id,
            ]);
        }
        echo "  - 附件: 2 张图片\n";

        $invoiceIds[] = $invoice->id;
    }

    // 5. 创建分享 Token（包含所有账单）
    $token = \App\Models\InvoiceShareToken::create([
        'token' => \App\Models\InvoiceShareToken::generateToken(),
        'invoice_ids' => $invoiceIds,
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_by' => $user->id,
        'expires_at' => now()->addMonths(3),
    ]);

    DB::commit();

    echo "\n====================================\n";
    echo "测试数据创建成功！\n";
    echo "====================================\n";
    echo "门店: {$store->name}\n";
    echo "客户: {$customer->name}\n";
    echo '账单数量: '.count($invoiceIds)."\n";
    echo "------------------------------------\n";
    echo "多账单测试 Token: {$token->token}\n";
    echo "小程序路径: /pages/bill/index?token={$token->token}\n";
    echo "====================================\n";

    // 额外创建单账单 Token
    $singleToken = \App\Models\InvoiceShareToken::create([
        'token' => \App\Models\InvoiceShareToken::generateToken(),
        'invoice_ids' => [$invoiceIds[0]],
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_by' => $user->id,
        'expires_at' => now()->addMonths(3),
    ]);
    echo "\n单账单测试 Token: {$singleToken->token}\n";
    echo "小程序路径: /pages/bill/index?token={$singleToken->token}\n";
    echo "====================================\n";

} catch (Exception $e) {
    DB::rollBack();
    echo '错误: '.$e->getMessage()."\n";
    exit(1);
}
