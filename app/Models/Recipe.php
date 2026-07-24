<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'product_id', 'version', 'expected_yield', 'yield_unit',
        'theoretical_waste_percent', 'status', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_yield' => 'decimal:3',
            'theoretical_waste_percent' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
