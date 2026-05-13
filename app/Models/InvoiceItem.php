<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'line_uid',
        'item_name',
        'item_description',
        'quantity',
        'unit_price',
        'subtotal',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    /**
     * 获取明细所属的账单
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * 模型事件：保存时自动计算小计并更新账单总金额
     */
    protected static function booted()
    {
        // 创建前自动生成稳定业务标识
        static::creating(function ($item) {
            if (empty($item->line_uid)) {
                $item->line_uid = (string) Str::uuid();
            }
        });

        // 保存前自动计算小计
        static::saving(function ($item) {
            $item->subtotal = $item->quantity * $item->unit_price;
        });

        // 保存后更新账单总金额
        static::saved(function ($item) {
            $item->invoice->calculateTotalAmount();
        });

        // 删除后更新账单总金额
        static::deleted(function ($item) {
            $item->invoice->calculateTotalAmount();
        });
    }
}
