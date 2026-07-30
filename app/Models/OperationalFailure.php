<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OperationalFailure extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
