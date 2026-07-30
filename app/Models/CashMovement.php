<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashMovement extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are immutable; create a reversal.'));
        static::deleting(fn () => throw new LogicException('Cash movements are immutable; create a reversal.'));
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }
}
