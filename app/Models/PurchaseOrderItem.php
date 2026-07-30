<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6', 'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3', 'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'subtotal' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
