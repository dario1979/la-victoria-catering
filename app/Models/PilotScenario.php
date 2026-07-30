<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PilotScenario extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'fixtures' => 'array',
            'result' => 'array',
            'report_paths' => 'array',
            'rehearsed_at' => 'immutable_datetime',
        ];
    }
}
