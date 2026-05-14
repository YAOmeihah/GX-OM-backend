<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerStoreStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'store_id',
        'total_debt',
        'last_transaction_at',
    ];

    protected $casts = [
        'total_debt' => 'decimal:2',
        'last_transaction_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
