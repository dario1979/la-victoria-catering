<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    protected $fillable = ['ingredient_product_id', 'quantity', 'unit'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }
}
