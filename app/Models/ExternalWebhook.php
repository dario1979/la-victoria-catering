<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['signature', 'headers', 'payload'])]
final class ExternalWebhook extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
