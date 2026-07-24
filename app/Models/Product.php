<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'type', 'unit', 'minimum_stock', 'price', 'active',
    ];

    protected function casts(): array
    {
        return ['minimum_stock' => 'decimal:3', 'price' => 'decimal:2', 'active' => 'boolean'];
    }
}
