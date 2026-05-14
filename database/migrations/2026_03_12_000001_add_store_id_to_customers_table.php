<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 客户门店隔离迁移
 *
 * 迁移逻辑：
 * 1. customers 表加 store_id 字段（先允许 null，迁移完再加约束）
 * 2. 单店客户 → 直接打上对应 store_id
 * 3. 跨店客户 → 克隆成多条记录，各自绑定对应门店，更新 invoices/payments/customer_store_stats 引用
 * 4. 无交易客户 → 分配给 id=1 的门店
 * 5. 加 NOT NULL 约束 + 外键 + 门店内唯一索引
 */
return new class extends Migration
{
    // 无交易客户默认分配的门店 ID
    const DEFAULT_STORE_ID = 1;

    public function up(): void
    {
        // ── 1. 加字段（先 nullable，迁移完再收紧）──────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
        });

        // ── 2. 单店客户：直接赋值 ──────────────────────────────────────────
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                UPDATE customers c
                JOIN (
                    SELECT customer_id, MIN(store_id) AS store_id
                    FROM invoices
                    GROUP BY customer_id
                    HAVING COUNT(DISTINCT store_id) = 1
                ) single ON c.id = single.customer_id
                SET c.store_id = single.store_id
                WHERE c.store_id IS NULL
            ");

            // 同样处理只有 payments 没有 invoices 的客户
            DB::statement("
                UPDATE customers c
                JOIN (
                    SELECT customer_id, MIN(store_id) AS store_id
                    FROM payments
                    GROUP BY customer_id
                    HAVING COUNT(DISTINCT store_id) = 1
                ) single ON c.id = single.customer_id
                SET c.store_id = single.store_id
                WHERE c.store_id IS NULL
            ");
        }

        // ── 3. 跨店客户：克隆 ─────────────────────────────────────────────
        // Note: GROUP_CONCAT with ORDER BY is MySQL-specific; skip entire block under SQLite
        if (DB::getDriverName() !== 'sqlite') {
            $crossStoreCustomers = DB::select("
                SELECT c.id, c.name, c.phone, c.email, c.address, c.id_card, c.remarks,
                       c.created_at, c.updated_at,
                       GROUP_CONCAT(DISTINCT i.store_id ORDER BY i.store_id) AS store_ids
                FROM customers c
                JOIN invoices i ON i.customer_id = c.id
                WHERE c.store_id IS NULL
                GROUP BY c.id, c.name, c.phone, c.email, c.address, c.id_card, c.remarks, c.created_at, c.updated_at
                HAVING COUNT(DISTINCT i.store_id) > 1
            ");

            foreach ($crossStoreCustomers as $customer) {
                $storeIds = explode(',', $customer->store_ids);
                $isFirst = true;

                foreach ($storeIds as $storeId) {
                    $storeId = (int) $storeId;

                    if ($isFirst) {
                        // 第一个门店：直接更新原记录
                        DB::table('customers')
                            ->where('id', $customer->id)
                            ->update(['store_id' => $storeId]);
                        $isFirst = false;
                    } else {
                        // 其余门店：克隆新记录
                        $newId = DB::table('customers')->insertGetId([
                            'store_id'   => $storeId,
                            'name'       => $customer->name,
                            'phone'      => $customer->phone,
                            'email'      => $customer->email,
                            'address'    => $customer->address,
                            'id_card'    => $customer->id_card,
                            'remarks'    => $customer->remarks,
                            'created_at' => $customer->created_at,
                            'updated_at' => $customer->updated_at,
                        ]);

                        // 更新该门店下的 invoices 引用
                        DB::table('invoices')
                            ->where('customer_id', $customer->id)
                            ->where('store_id', $storeId)
                            ->update(['customer_id' => $newId]);

                        // 更新该门店下的 payments 引用
                        DB::table('payments')
                            ->where('customer_id', $customer->id)
                            ->where('store_id', $storeId)
                            ->update(['customer_id' => $newId]);

                        // 更新 customer_store_stats 引用
                        DB::table('customer_store_stats')
                            ->where('customer_id', $customer->id)
                            ->where('store_id', $storeId)
                            ->update(['customer_id' => $newId]);

                        // 更新 invoice_share_tokens 引用
                        DB::table('invoice_share_tokens')
                            ->where('customer_id', $customer->id)
                            ->where('store_id', $storeId)
                            ->update(['customer_id' => $newId]);
                    }
                }
            }
        }

        // ── 4. 无交易客户：分配给默认门店 ────────────────────────────────
        DB::table('customers')
            ->whereNull('store_id')
            ->update(['store_id' => self::DEFAULT_STORE_ID]);

        // ── 5. 加约束、外键、唯一索引 ─────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            // 改为 NOT NULL
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            // 外键
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            // 门店内客户名唯一（允许不同门店有同名客户）
            $table->unique(['store_id', 'name'], 'unq_store_customer_name');
        });
    }

    public function down(): void
    {
        // 警告：此迁移包含数据变更（跨店客户克隆 + customer_id 引用重写）
        // down() 仅回滚表结构，数据层面的变更不可自动还原，需手动处理
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('unq_store_customer_name');
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
