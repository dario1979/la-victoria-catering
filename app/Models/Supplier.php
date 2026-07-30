<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'lead_time_days' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }
}
