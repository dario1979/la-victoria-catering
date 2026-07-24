<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;

final class OrderPolicy
{
    public function create(User $user): bool
    {
        return app(TenantContext::class)->can('owner', 'admin', 'sales');
    }

    public function transition(User $user, Order $order): bool
    {
        $tenant = app(TenantContext::class);

        return $order->organization_id === $tenant->organization->id
            && $order->branch_id === $tenant->branch->id
            && $tenant->can('owner', 'admin', 'sales', 'production');
    }

    public function recordPayment(User $user, Order $order): bool
    {
        $tenant = app(TenantContext::class);

        return $order->organization_id === $tenant->organization->id
            && $order->branch_id === $tenant->branch->id
            && $tenant->can('owner', 'admin', 'sales', 'finance');
    }
}
