<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

final class DeliverOrder
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function execute(
        Order $order,
        TenantContext $tenant,
        int $userId,
        string $method,
        ?string $notes,
    ): Order {
        return DB::transaction(function () use ($order, $tenant, $userId, $method, $notes): Order {
            $this->workflow->consumeReservations($order, $userId);
            $order = $this->workflow->transition($order, 'delivered', $userId);
            $order->update([
                'delivery_method' => $method,
                'delivery_notes' => $notes,
                'delivered_at' => now(),
                'delivered_by' => $userId,
            ]);
            DB::table('audit_logs')->insert([
                'event' => 'order.delivered', 'subject_type' => Order::class, 'subject_id' => $order->id,
                'actor_type' => User::class, 'actor_id' => $userId,
                'context' => json_encode(['branch_id' => $tenant->branch->id, 'method' => $method]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $order->fresh(['transitions']);
        });
    }
}
