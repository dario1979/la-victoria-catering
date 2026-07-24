<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatch extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'order_id', 'recipe_id', 'status',
        'planned_quantity', 'actual_yield', 'waste_quantity', 'unit',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:3',
            'actual_yield' => 'decimal:3',
            'waste_quantity' => 'decimal:3',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
