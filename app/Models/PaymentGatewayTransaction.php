<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'refunded_cents' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
