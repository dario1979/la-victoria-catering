<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    protected $fillable = [
        'product_id', 'location_id', 'code', 'unit', 'quantity',
        'reserved_quantity', 'expires_at', 'status',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
