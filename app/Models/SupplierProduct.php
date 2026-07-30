<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierProduct extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['current_price'];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
            'minimum_quantity' => 'decimal:3',
            'preferred' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class)->orderByDesc('valid_from')->orderByDesc('id');
    }

    public function currentPrice(): ?SupplierProductPrice
    {
        return $this->prices()->whereDate('valid_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->first();
    }

    public function getCurrentPriceAttribute(): ?array
    {
        $price = $this->relationLoaded('prices')
            ? $this->prices->first(fn (SupplierProductPrice $candidate) => $candidate->valid_from->lte(today())
                && ($candidate->valid_until === null || $candidate->valid_until->gte(today())))
            : $this->currentPrice();

        return $price?->only(['id', 'price', 'currency', 'valid_from', 'valid_until']);
    }
}
