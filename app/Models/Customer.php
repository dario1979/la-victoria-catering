<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'tax_id', 'tax_condition', 'email', 'phone',
        'addresses', 'credit_limit', 'active',
    ];

    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'credit_limit' => 'decimal:2',
            'active' => 'boolean',
        ];
    }
}
