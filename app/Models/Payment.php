<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments are immutable; create a reversal.'));
        static::deleting(fn () => throw new LogicException('Payments are immutable; create a reversal.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'amount_cents' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
