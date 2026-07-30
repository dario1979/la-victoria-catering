<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'internal_amount_cents' => 'integer', 'external_amount_cents' => 'integer',
            'difference_cents' => 'integer', 'external_date' => 'date', 'reconciled_at' => 'datetime',
        ];
    }
}
