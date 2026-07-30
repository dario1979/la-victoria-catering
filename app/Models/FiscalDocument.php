<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalDocument extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Fiscal documents cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'net_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer',
            'safe_request' => 'array', 'safe_response' => 'array', 'cae_expires_on' => 'date',
        ];
    }
}
