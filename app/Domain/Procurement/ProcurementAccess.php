<?php

namespace App\Domain\Procurement;

use App\Support\TenantContext;

final class ProcurementAccess
{
    private const ROLES = [
        'view-suppliers' => ['owner', 'admin', 'purchasing', 'inventory', 'production'],
        'manage-suppliers' => ['owner', 'admin', 'purchasing'],
        'view-orders' => ['owner', 'admin', 'purchasing', 'inventory', 'production', 'finance'],
        'manage-orders' => ['owner', 'admin', 'purchasing'],
        'receive-orders' => ['owner', 'admin', 'purchasing', 'inventory'],
    ];

    public function authorize(TenantContext $tenant, string $capability): void
    {
        abort_unless(in_array($tenant->role, self::ROLES[$capability] ?? [], true), 403);
    }
}
