<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountPayable extends Model
{
    protected $table = 'accounts_payable';

    protected $guarded = ['id'];

    protected $appends = ['operational_status'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'due_on' => 'date',
            'total_cents' => 'integer', 'paid_cents' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountPayablePayment::class, 'account_payable_id');
    }

    public function getOperationalStatusAttribute(): string
    {
        return $this->status !== 'paid' && $this->due_on->isPast() ? 'overdue' : $this->status;
    }
}
