<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening_balance_cents' => 'integer', 'expected_balance_cents' => 'integer',
            'counted_balance_cents' => 'integer', 'difference_cents' => 'integer',
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'difference_approved_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
