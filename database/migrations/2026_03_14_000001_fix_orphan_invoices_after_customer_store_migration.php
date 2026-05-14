<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复客户门店隔离迁移遗留的孤儿账单问题
 *
 * 问题根因：
 *   原迁移第2步先用 payments 表给跨店客户打上了 store_id，
 *   导致第3步的跨店检测（WHERE store_id IS NULL）漏掉了这些客户，
 *   其在其他门店的 invoices/payments 未被重新指向克隆记录。
 *
 * 修复逻辑：
 *   找出所有 invoice.store_id ≠ customer.store_id 的孤儿账单，
 *   按 (原客户, 孤儿门店) 分组，克隆客户记录并重新指向所有关联数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 诊断：找出孤儿账单 ────────────────────────────────────────────
        // invoice.store_id 与其 customer.store_id 不一致，说明该客户未被正确克隆
        $orphanGroups = DB::select('
            SELECT
                c.id          AS original_customer_id,
                c.name        AS customer_name,
                c.phone,
                c.email,
                c.address,
                c.id_card,
                c.remarks,
                c.created_at,
                c.updated_at,
                i.store_id    AS orphan_store_id
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.store_id != c.store_id
            GROUP BY c.id, c.name, c.phone, c.email, c.address,
                     c.id_card, c.remarks, c.created_at, c.updated_at,
                     i.store_id
        ');

        if (empty($orphanGroups)) {
            // 无孤儿数据，跳过
            return;
        }

        foreach ($orphanGroups as $group) {
            $originalId = $group->original_customer_id;
            $orphanStoreId = (int) $group->orphan_store_id;

            // 检查该门店是否已存在同名客户（可能之前部分修复过）
            $existing = DB::table('customers')
                ->where('store_id', $orphanStoreId)
                ->where('name', $group->customer_name)
                ->first();

            if ($existing) {
                // 已存在同名客户，直接把孤儿账单指向它
                $targetId = $existing->id;
            } else {
                // 克隆客户记录到孤儿门店
                $targetId = DB::table('customers')->insertGetId([
                    'store_id' => $orphanStoreId,
                    'name' => $group->customer_name,
                    'phone' => $group->phone,
                    'email' => $group->email,
                    'address' => $group->address,
                    'id_card' => $group->id_card,
                    'remarks' => $group->remarks,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);
            }

            // 重新指向 invoices
            DB::table('invoices')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);

            // 重新指向 payments
            DB::table('payments')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);

            // 重新指向 customer_store_stats
            DB::table('customer_store_stats')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);

            // 重新指向 invoice_share_tokens
            DB::table('invoice_share_tokens')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);
        }

        // ── 同样修复 payments 表中的孤儿记录 ─────────────────────────────
        $orphanPaymentGroups = DB::select('
            SELECT
                c.id          AS original_customer_id,
                c.name        AS customer_name,
                c.phone,
                c.email,
                c.address,
                c.id_card,
                c.remarks,
                c.created_at,
                c.updated_at,
                p.store_id    AS orphan_store_id
            FROM payments p
            JOIN customers c ON c.id = p.customer_id
            WHERE p.store_id != c.store_id
            GROUP BY c.id, c.name, c.phone, c.email, c.address,
                     c.id_card, c.remarks, c.created_at, c.updated_at,
                     p.store_id
        ');

        foreach ($orphanPaymentGroups as $group) {
            $originalId = $group->original_customer_id;
            $orphanStoreId = (int) $group->orphan_store_id;

            $existing = DB::table('customers')
                ->where('store_id', $orphanStoreId)
                ->where('name', $group->customer_name)
                ->first();

            if ($existing) {
                $targetId = $existing->id;
            } else {
                $targetId = DB::table('customers')->insertGetId([
                    'store_id' => $orphanStoreId,
                    'name' => $group->customer_name,
                    'phone' => $group->phone,
                    'email' => $group->email,
                    'address' => $group->address,
                    'id_card' => $group->id_card,
                    'remarks' => $group->remarks,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);
            }

            DB::table('payments')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);

            DB::table('customer_store_stats')
                ->where('customer_id', $originalId)
                ->where('store_id', $orphanStoreId)
                ->update(['customer_id' => $targetId]);
        }
    }

    public function down(): void
    {
        // 数据修复类迁移，down() 不可自动还原
    }
};
