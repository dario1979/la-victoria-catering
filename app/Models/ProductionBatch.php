<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionBatch extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'order_id', 'recipe_id', 'status',
        'planned_quantity', 'actual_yield', 'waste_quantity', 'unit',
        'recipe_snapshot', 'destination_location_id', 'manufactured_at',
        'expires_at', 'observations', 'started_at', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:3',
            'actual_yield' => 'decimal:3',
            'waste_quantity' => 'decimal:3',
            'recipe_snapshot' => 'array',
            'manufactured_at' => 'datetime',
            'expires_at' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }

    public function producedLot(): HasOne
    {
        return $this->hasOne(InventoryLot::class);
    }
}
