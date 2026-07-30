<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomerAccountEntry extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Customer account entries are immutable; create a reversal.'));
        static::deleting(fn () => throw new LogicException('Customer account entries are immutable; create a reversal.'));
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'due_on' => 'date', 'occurred_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
