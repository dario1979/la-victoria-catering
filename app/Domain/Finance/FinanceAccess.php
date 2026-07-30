<?php

namespace App\Domain\Finance;

use App\Support\TenantContext;

final class FinanceAccess
{
    private const ROLES = [
        'view' => ['owner', 'admin', 'sales', 'finance'],
        'operate-cash' => ['owner', 'admin', 'sales', 'finance'],
        'manage-cash' => ['owner', 'admin', 'finance'],
        'manage-ledger' => ['owner', 'admin', 'finance'],
        'approve-difference' => ['owner', 'admin', 'finance'],
        'manage-payables' => ['owner', 'admin', 'finance'],
        'reconcile' => ['owner', 'admin', 'finance'],
    ];

    public function authorize(TenantContext $tenant, string $capability): void
    {
        abort_unless(in_array($tenant->role, self::ROLES[$capability] ?? [], true), 403);
    }
}
