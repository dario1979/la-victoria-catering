<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'product_id', 'location_id', 'code', 'unit',
        'quantity', 'reserved_quantity', 'manufactured_at', 'expires_at', 'status',
        'production_batch_id', 'recipe_id', 'recipe_version', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'datetime', 'expires_at' => 'date',
            'quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function productionConsumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }
}
