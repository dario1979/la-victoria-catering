<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'file_sha256', 'failure_reason'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'preview' => 'array',
            'errors' => 'array',
            'created_records' => 'array',
            'validated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'file_purged_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
