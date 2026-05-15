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

        foreach ($orphanGroups as $group) {
            DB::transaction(function () use ($group): void {
                $originalId = $group->original_customer_id;
                $orphanStoreId = (int) $group->orphan_store_id;

                // 检查该门店是否已存在同名客户（可能之前部分修复过）
                $existing = $this->findMatchingCustomer($group, $orphanStoreId);

                if ($existing) {
                    // 已存在同名客户，直接把孤儿账单指向它
                    $targetId = $existing->id;
                } else {
                    // 克隆客户记录到孤儿门店
                    $targetId = $this->cloneCustomerToStore($group, $orphanStoreId);
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
            });
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
            DB::transaction(function () use ($group): void {
                $originalId = $group->original_customer_id;
                $orphanStoreId = (int) $group->orphan_store_id;

                $existing = $this->findMatchingCustomer($group, $orphanStoreId);

                if ($existing) {
                    $targetId = $existing->id;
                } else {
                    $targetId = $this->cloneCustomerToStore($group, $orphanStoreId);
                }

                DB::table('payments')
                    ->where('customer_id', $originalId)
                    ->where('store_id', $orphanStoreId)
                    ->update(['customer_id' => $targetId]);

                DB::table('customer_store_stats')
                    ->where('customer_id', $originalId)
                    ->where('store_id', $orphanStoreId)
                    ->update(['customer_id' => $targetId]);
            });
        }
    }

    private function findMatchingCustomer(object $group, int $storeId): ?object
    {
        return DB::table('customers')
            ->where('store_id', $storeId)
            ->get()
            ->filter(fn (object $customer): bool => $this->hasCandidateName($group, $customer))
            ->sort(fn (object $a, object $b): int => $this->candidateSortScore($b, $storeId) <=> $this->candidateSortScore($a, $storeId)
                ?: $a->id <=> $b->id)
            ->first(fn (object $customer): bool => $this->hasCompatibleIdentity($group, $customer, $storeId));
    }

    private function hasCompatibleIdentity(object $group, object $customer, int $storeId): bool
    {
        if (! $this->hasCandidateName($group, $customer)) {
            return false;
        }

        $hasMatch = false;
        $hasSourceIdentity = false;

        foreach (['phone', 'email', 'id_card'] as $field) {
            $sourceValue = $group->{$field};
            $targetValue = $customer->{$field};

            if (empty($sourceValue)) {
                continue;
            }

            $hasSourceIdentity = true;

            if (empty($targetValue)) {
                continue;
            }

            if ($sourceValue !== $targetValue) {
                return false;
            }

            $hasMatch = true;
        }

        if ($hasSourceIdentity) {
            return $hasMatch;
        }

        return $this->isNoIdentityMigrationClone($group, $customer)
            && $this->hasTargetStoreRows($customer->id, $storeId);
    }

    private function hasCandidateName(object $group, object $customer): bool
    {
        return $customer->name === $group->customer_name
            || str_starts_with($customer->name, $group->customer_name.'#迁移');
    }

    private function cloneCustomerToStore(object $group, int $storeId): int
    {
        return DB::table('customers')->insertGetId([
            'store_id' => $storeId,
            'name' => $this->uniqueCustomerName(
                $group->customer_name,
                $storeId,
                $this->hasNoIdentity($group),
            ),
            'phone' => $group->phone,
            'email' => $group->email,
            'address' => $group->address,
            'id_card' => $group->id_card,
            'remarks' => $group->remarks,
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ]);
    }

    private function uniqueCustomerName(string $name, int $storeId, bool $forceMigrationSuffix = false): string
    {
        if (! $forceMigrationSuffix && ! DB::table('customers')->where('store_id', $storeId)->where('name', $name)->exists()) {
            return $name;
        }

        for ($index = 1; ; $index++) {
            $candidate = "{$name}#迁移{$index}";

            if (! DB::table('customers')->where('store_id', $storeId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    private function candidateSortScore(object $customer, int $storeId): int
    {
        return $this->hasTargetStoreRows($customer->id, $storeId) ? 1 : 0;
    }

    private function isNoIdentityMigrationClone(object $group, object $customer): bool
    {
        if (! $this->hasNoIdentity($customer)) {
            return false;
        }

        if ($customer->name !== $group->customer_name && ! str_starts_with($customer->name, $group->customer_name.'#迁移')) {
            return false;
        }

        foreach (['address', 'remarks', 'created_at', 'updated_at'] as $field) {
            if ((string) $customer->{$field} !== (string) $group->{$field}) {
                return false;
            }
        }

        return true;
    }

    private function hasNoIdentity(object $customer): bool
    {
        foreach (['phone', 'email', 'id_card'] as $field) {
            if (! empty($customer->{$field})) {
                return false;
            }
        }

        return true;
    }

    private function hasTargetStoreRows(int $customerId, int $storeId): bool
    {
        foreach (['invoices', 'payments', 'customer_store_stats', 'invoice_share_tokens'] as $table) {
            if (DB::table($table)->where('customer_id', $customerId)->where('store_id', $storeId)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // 数据修复类迁移，down() 不可自动还原
    }
};
