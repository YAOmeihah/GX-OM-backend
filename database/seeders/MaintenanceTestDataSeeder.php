<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 维护功能测试数据填充器
 * 
 * 创建以下测试数据:
 * 1. 超过3个月的已结清账单 (可被历史清理)
 * 2. 孤立的账单明细 (父账单不存在)
 * 3. 孤立的还款分配 (父还款或账单不存在)
 */
class MaintenanceTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('正在创建维护测试数据...');

        // 清理已存在的测试数据
        $this->command->info('清理已存在的测试数据...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Invoice::where('invoice_number', 'LIKE', 'TEST-OLD-%')->forceDelete();
        Payment::where('payment_number', 'LIKE', 'TEST-PAY-%')->forceDelete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 获取必要的关联数据
        $store = Store::first();
        if (!$store) {
            $this->command->error('请先创建门店数据');
            return;
        }

        $customer = Customer::first();
        if (!$customer) {
            $this->command->error('请先创建客户数据');
            return;
        }

        $user = User::first();
        if (!$user) {
            $this->command->error('请先创建用户数据');
            return;
        }

        // 1. 创建旧的已结清账单 (6个月前)
        $this->command->info('创建旧的已结清账单...');
        $oldDate = Carbon::now()->subMonths(6);

        for ($i = 1; $i <= 5; $i++) {
            $amount = 1000 + ($i * 100);

            $invoice = Invoice::create([
                'invoice_number' => 'TEST-OLD-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'created_by' => $user->id,
                'amount' => $amount,
                'paid_amount' => $amount,
                'status' => 'paid',
                'created_at' => $oldDate,
                'updated_at' => $oldDate,
            ]);

            // 创建账单明细
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_name' => '测试商品 ' . $i,
                'quantity' => 1,
                'unit_price' => $amount,
                'subtotal' => $amount,
            ]);

            // 创建对应的还款
            $payment = Payment::create([
                'payment_number' => 'TEST-PAY-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'received_by' => $user->id,
                'amount' => $amount,
                'allocated_amount' => $amount,
                'payment_method' => 'cash',
                'created_at' => $oldDate,
                'updated_at' => $oldDate,
            ]);

            // 创建还款分配
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'allocated_by' => $user->id,
            ]);
        }
        $this->command->info('✓ 创建了 5 个旧的已结清账单');

        // 2. 创建孤立的账单明细
        $this->command->info('创建孤立的账单明细...');

        // 找一个不存在的账单ID
        $maxInvoiceId = Invoice::max('id') ?? 0;
        $orphanInvoiceId = $maxInvoiceId + 1000;

        // 暂时禁用外键约束
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        for ($i = 1; $i <= 3; $i++) {
            DB::table('invoice_items')->insert([
                'invoice_id' => $orphanInvoiceId + $i,
                'item_name' => '孤立商品 ' . $i,
                'quantity' => 1,
                'unit_price' => 500,
                'subtotal' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. 创建孤立的还款分配
        $this->command->info('创建孤立的还款分配...');

        $maxPaymentId = Payment::max('id') ?? 0;
        $orphanPaymentId = $maxPaymentId + 1000;

        for ($i = 1; $i <= 2; $i++) {
            DB::table('payment_allocations')->insert([
                'payment_id' => $orphanPaymentId + $i,
                'invoice_id' => $orphanInvoiceId + $i,
                'amount' => 300,
                'allocated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 重新启用外键约束
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command->info('✓ 创建了 3 个孤立的账单明细');
        $this->command->info('✓ 创建了 2 个孤立的还款分配');

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('测试数据创建完成！');
        $this->command->info('');
        $this->command->info('可清理数据统计:');
        $this->command->info('- 历史清理: 5 个旧账单 + 5 个旧还款');
        $this->command->info('- 孤立数据: 3 个账单明细 + 2 个还款分配');
        $this->command->info('========================================');
    }
}
