<?php

namespace App\Services\Audit;

class InvoiceAuditDiffBuilder
{
    /**
     * 账单基础字段（排除明细和系统字段）
     */
    private const BASIC_FIELDS = [
        'customer_id', 'amount', 'due_date', 'description', 'status',
        'paid_amount', 'invoice_date',
    ];

    /**
     * 明细字段（用于逐字段比对）
     */
    private const ITEM_FIELDS = [
        'item_name', 'item_description', 'quantity', 'unit_price', 'subtotal', 'sort_order',
    ];

    /**
     * 构建账单创建的结构化 change_payload
     */
    public function buildForCreate(array $data, string $invoiceNumber, ?int $customerId = null): array
    {
        $items = $data['items'] ?? [];
        $itemCount = count($items);

        return [
            'schema_version' => 1,
            'domain'         => 'invoice',
            'event'          => 'invoice.created',
            'target'         => [
                'invoice_number' => $invoiceNumber,
                'customer_id'    => $customerId,
            ],
            'summary' => [
                'title'      => '创建账单',
                'subtitle'   => $itemCount > 0 ? "创建了 {$itemCount} 条明细" : '创建空账单',
                'highlights' => $itemCount > 0 ? [['type' => 'added', 'count' => $itemCount]] : [],
            ],
            'stats' => [
                'basic_change_count'  => 0,
                'item_added_count'    => $itemCount,
                'item_removed_count'  => 0,
                'item_updated_count'  => 0,
                'total_change_count'  => $itemCount,
            ],
            'basic_changes' => $this->extractBasicFields($data),
            'item_changes'  => [
                'added'   => array_map(fn($item) => [
                    'line_uid'     => $item['line_uid'] ?? null,
                    'display_name' => $item['item_name'] ?? '',
                    'item'         => $item,
                ], $items),
                'removed' => [],
                'updated' => [],
            ],
            'financial_effect' => [
                'old_amount' => null,
                'new_amount' => $data['amount'] ?? null,
                'delta'      => $data['amount'] ?? null,
            ],
        ];
    }

    /**
     * 构建账单删除的结构化 change_payload
     */
    public function buildForDelete(array $data, string $invoiceNumber, ?int $customerId = null): array
    {
        $items = $data['items'] ?? [];
        $itemCount = count($items);

        return [
            'schema_version' => 1,
            'domain'         => 'invoice',
            'event'          => 'invoice.deleted',
            'target'         => [
                'invoice_number' => $invoiceNumber,
                'customer_id'    => $customerId,
            ],
            'summary' => [
                'title'      => '删除账单',
                'subtitle'   => $itemCount > 0 ? "删除了 {$itemCount} 条明细" : '删除空账单',
                'highlights' => $itemCount > 0 ? [['type' => 'removed', 'count' => $itemCount]] : [],
            ],
            'stats' => [
                'basic_change_count'  => 0,
                'item_added_count'    => 0,
                'item_removed_count'  => $itemCount,
                'item_updated_count'  => 0,
                'total_change_count'  => $itemCount,
            ],
            'basic_changes' => $this->extractBasicFieldsForDelete($data),
            'item_changes'  => [
                'added'   => [],
                'removed' => array_map(fn($item) => [
                    'line_uid'     => $item['line_uid'] ?? null,
                    'display_name' => $item['item_name'] ?? '',
                    'item'         => $item,
                ], $items),
                'updated' => [],
            ],
            'financial_effect' => [
                'old_amount' => $data['amount'] ?? null,
                'new_amount' => null,
                'delta'      => isset($data['amount']) ? -((float)$data['amount']) : null,
            ],
        ];
    }

    /**
     * 提取基础字段作为变更记录（用于创建）
     */
    private function extractBasicFields(array $data): array
    {
        $changes = [];

        foreach (self::BASIC_FIELDS as $field) {
            if (isset($data[$field])) {
                $changes[] = [
                    'field'  => $field,
                    'before' => null,
                    'after'  => $data[$field],
                ];
            }
        }

        return $changes;
    }

    /**
     * 提取基础字段作为变更记录（用于删除）
     */
    private function extractBasicFieldsForDelete(array $data): array
    {
        $changes = [];

        foreach (self::BASIC_FIELDS as $field) {
            if (isset($data[$field])) {
                $changes[] = [
                    'field'  => $field,
                    'before' => $data[$field],
                    'after'  => null,
                ];
            }
        }

        return $changes;
    }

    /**
     * 构建账单更新的结构化 change_payload
     */
    public function build(array $oldData, array $newData, string $invoiceNumber, ?int $customerId = null): array
    {
        $basicChanges = $this->diffBasicFields($oldData, $newData);
        $itemChanges  = $this->diffItems($oldData['items'] ?? [], $newData['items'] ?? []);

        $addedCount   = count($itemChanges['added']);
        $removedCount = count($itemChanges['removed']);
        $updatedCount = count($itemChanges['updated']);
        $totalItemChanges = $addedCount + $removedCount + $updatedCount;

        return [
            'schema_version' => 1,
            'domain'         => 'invoice',
            'event'          => 'invoice.updated',
            'target'         => [
                'invoice_number' => $invoiceNumber,
                'customer_id'    => $customerId,
            ],
            'summary' => $this->buildSummary($basicChanges, $addedCount, $removedCount, $updatedCount),
            'stats'   => [
                'basic_change_count'  => count($basicChanges),
                'item_added_count'    => $addedCount,
                'item_removed_count'  => $removedCount,
                'item_updated_count'  => $updatedCount,
                'total_change_count'  => count($basicChanges) + $totalItemChanges,
            ],
            'basic_changes' => $basicChanges,
            'item_changes'  => $itemChanges,
            'financial_effect' => [
                'old_amount' => $oldData['amount'] ?? null,
                'new_amount' => $newData['amount'] ?? null,
                'delta'      => isset($oldData['amount'], $newData['amount'])
                    ? round((float)$newData['amount'] - (float)$oldData['amount'], 2)
                    : null,
            ],
        ];
    }

    /**
     * 对比账单基础字段
     */
    private function diffBasicFields(array $oldData, array $newData): array
    {
        $changes = [];

        foreach (self::BASIC_FIELDS as $field) {
            $oldVal = $oldData[$field] ?? null;
            $newVal = $newData[$field] ?? null;

            // 数值类型做精度归一化再比较
            if (is_numeric($oldVal) && is_numeric($newVal)) {
                if ((float)$oldVal === (float)$newVal) {
                    continue;
                }
            } elseif ($oldVal === $newVal) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'before' => $oldVal,
                'after'  => $newVal,
            ];
        }

        return $changes;
    }

    /**
     * 基于 line_uid 对比明细变化
     */
    private function diffItems(array $oldItems, array $newItems): array
    {
        $oldMap = [];
        foreach ($oldItems as $item) {
            if (!empty($item['line_uid'])) {
                $oldMap[$item['line_uid']] = $item;
            }
        }

        $newMap = [];
        foreach ($newItems as $item) {
            if (!empty($item['line_uid'])) {
                $newMap[$item['line_uid']] = $item;
            }
        }

        $added   = [];
        $removed = [];
        $updated = [];

        // 新增：在新列表里有、旧列表里没有
        foreach ($newMap as $uid => $newItem) {
            if (!isset($oldMap[$uid])) {
                $added[] = [
                    'line_uid'     => $uid,
                    'display_name' => $newItem['item_name'] ?? '',
                    'item'         => $newItem,
                ];
            }
        }

        // 删除：在旧列表里有、新列表里没有
        foreach ($oldMap as $uid => $oldItem) {
            if (!isset($newMap[$uid])) {
                $removed[] = [
                    'line_uid'     => $uid,
                    'display_name' => $oldItem['item_name'] ?? '',
                    'item'         => $oldItem,
                ];
            }
        }

        // 修改：两边都有，逐字段比对
        foreach ($newMap as $uid => $newItem) {
            if (!isset($oldMap[$uid])) {
                continue;
            }

            $oldItem      = $oldMap[$uid];
            $fieldChanges = [];

            foreach (self::ITEM_FIELDS as $field) {
                $oldVal = $oldItem[$field] ?? null;
                $newVal = $newItem[$field] ?? null;

                if (is_numeric($oldVal) && is_numeric($newVal)) {
                    if ((float)$oldVal === (float)$newVal) {
                        continue;
                    }
                } elseif ($oldVal === $newVal) {
                    continue;
                }

                $fieldChanges[] = [
                    'field'  => $field,
                    'before' => $oldVal,
                    'after'  => $newVal,
                ];
            }

            if (!empty($fieldChanges)) {
                $updated[] = [
                    'line_uid'      => $uid,
                    'display_name'  => $newItem['item_name'] ?? '',
                    'before'        => $oldItem,
                    'after'         => $newItem,
                    'field_changes' => $fieldChanges,
                ];
            }
        }

        return compact('added', 'removed', 'updated');
    }

    /**
     * 生成轻量摘要
     */
    private function buildSummary(array $basicChanges, int $added, int $removed, int $updated): array
    {
        $parts = [];

        if (!empty($basicChanges)) {
            $parts[] = '修改了基础信息';
        }
        if ($added > 0) {
            $parts[] = "新增 {$added} 条明细";
        }
        if ($removed > 0) {
            $parts[] = "删除 {$removed} 条明细";
        }
        if ($updated > 0) {
            $parts[] = "修改 {$updated} 条明细";
        }

        $highlights = [];
        if ($added > 0)   $highlights[] = ['type' => 'added',   'count' => $added];
        if ($removed > 0) $highlights[] = ['type' => 'removed', 'count' => $removed];
        if ($updated > 0) $highlights[] = ['type' => 'updated', 'count' => $updated];

        return [
            'title'      => '修改账单',
            'subtitle'   => empty($parts) ? '无实质变更' : implode('，', $parts),
            'highlights' => $highlights,
        ];
    }
}
