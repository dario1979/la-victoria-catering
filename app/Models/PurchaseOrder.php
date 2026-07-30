<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'date', 'expected_at' => 'date',
            'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'approved_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(PurchaseOrderTransition::class);
    }
}
