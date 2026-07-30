<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountPayablePayment extends Model
{
    protected $table = 'accounts_payable_payments';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payable payments are immutable; create a reversal.'));
        static::deleting(fn () => throw new LogicException('Payable payments are immutable; create a reversal.'));
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'occurred_at' => 'datetime'];
    }
}
