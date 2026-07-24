<?php

namespace App\Domain\Orders;

use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderWorkflow
{
    private const TRANSITIONS = [
        'draft' => ['confirmed', 'cancelled'],
        'confirmed' => ['in_production', 'cancelled'],
        'in_production' => ['ready', 'cancelled'],
        'ready' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function allowedTransitions(Order $order): array
    {
        return self::TRANSITIONS[$order->status] ?? [];
    }

    public function transition(Order $order, string $target, ?int $actorId = null): Order
    {
        $result = DB::transaction(function () use ($order, $target, $actorId): Order|array {
            $order = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            $allowed = self::TRANSITIONS[$order->status] ?? [];
            if (! in_array($target, $allowed, true)) {
                DB::table('order_transitions')->insert([
                    'order_id' => $order->id, 'from_status' => $order->status,
                    'to_status' => $target, 'accepted' => false,
                    'actor_type' => $actorId ? User::class : null, 'actor_id' => $actorId,
                    'reason' => 'invalid_transition', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['from' => $order->status, 'allowed' => $allowed];
            }
            if ($target === 'confirmed') {
                $this->reserve($order);
            }
            if ($target === 'cancelled') {
                $this->releaseReservations($order);
            }
            $from = $order->status;
            $order->update(['status' => $target]);
            DB::table('order_transitions')->insert([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $target,
                'accepted' => true, 'actor_type' => $actorId ? User::class : null, 'actor_id' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'event' => 'order.transitioned', 'subject_type' => Order::class,
                'subject_id' => $order->id, 'actor_type' => $actorId ? User::class : null,
                'actor_id' => $actorId, 'context' => json_encode(compact('from', 'target')),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $order->fresh('items');
        });

        if (is_array($result)) {
            throw ValidationException::withMessages([
                'status' => ["Transition {$result['from']} → {$target} is not allowed.", 'allowed' => $result['allowed']],
            ]);
        }

        return $result;
    }

    private function reserve(Order $order): void
    {
        foreach ($order->items as $item) {
            $remaining = Decimal::toScaledInt($item->getRawOriginal('quantity'), 3);
            $lots = InventoryLot::query()->lockForUpdate()
                ->where('product_id', $item->product_id)->where('status', 'available')
                ->whereHas('location', fn ($query) => $query
                    ->where('organization_id', $order->organization_id)
                    ->where('branch_id', $order->branch_id)
                    ->where('active', true))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->get();
            foreach ($lots as $lot) {
                $available = Decimal::toScaledInt($lot->getRawOriginal('quantity'), 3)
                    - Decimal::toScaledInt($lot->getRawOriginal('reserved_quantity'), 3);
                $take = min($remaining, max(0, $available));
                if ($take <= 0) {
                    continue;
                }
                $nextReserved = Decimal::toScaledInt($lot->getRawOriginal('reserved_quantity'), 3) + $take;
                $lot->update(['reserved_quantity' => Decimal::fromScaledInt($nextReserved, 3)]);
                DB::table('stock_reservations')->insert([
                    'order_id' => $order->id, 'inventory_lot_id' => $lot->id,
                    'product_id' => $item->product_id, 'location_id' => $lot->location_id,
                    'unit' => $lot->unit, 'quantity' => Decimal::fromScaledInt($take, 3), 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $remaining -= $take;
                if ($remaining <= 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                throw ValidationException::withMessages(['stock' => ["Insufficient non-expired stock for product {$item->product_id}."]]);
            }
        }
    }

    private function releaseReservations(Order $order): void
    {
        $reservations = DB::table('stock_reservations')
            ->where('order_id', $order->id)->where('status', 'active')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($reservation->inventory_lot_id);
            $reserved = Decimal::toScaledInt($lot->getRawOriginal('reserved_quantity'), 3);
            $release = Decimal::toScaledInt($reservation->quantity, 3);
            $lot->update(['reserved_quantity' => Decimal::fromScaledInt(max(0, $reserved - $release), 3)]);
            DB::table('stock_reservations')->where('id', $reservation->id)->update([
                'status' => 'released', 'updated_at' => now(),
            ]);
        }
    }

    public function consumeReservations(Order $order, int $actorId): void
    {
        $reservations = DB::table('stock_reservations')
            ->where('order_id', $order->id)->where('status', 'active')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($reservation->inventory_lot_id);
            $quantity = Decimal::toScaledInt($lot->getRawOriginal('quantity'), 3);
            $reserved = Decimal::toScaledInt($lot->getRawOriginal('reserved_quantity'), 3);
            $consume = Decimal::toScaledInt($reservation->quantity, 3);
            if ($quantity < $consume || $reserved < $consume) {
                throw ValidationException::withMessages(['stock' => ['Reserved stock is no longer available for delivery.']]);
            }
            $lot->update([
                'quantity' => Decimal::fromScaledInt($quantity - $consume, 3),
                'reserved_quantity' => Decimal::fromScaledInt($reserved - $consume, 3),
            ]);
            DB::table('stock_reservations')->where('id', $reservation->id)->update([
                'status' => 'consumed', 'updated_at' => now(),
            ]);
            DB::table('stock_movements')->insert([
                'organization_id' => $order->organization_id, 'branch_id' => $order->branch_id,
                'product_id' => $reservation->product_id, 'location_id' => $reservation->location_id,
                'inventory_lot_id' => $reservation->inventory_lot_id, 'unit' => $reservation->unit,
                'quantity' => Decimal::fromScaledInt(-$consume, 3), 'type' => 'consumption',
                'reason' => 'order_delivered', 'reference_type' => Order::class, 'reference_id' => $order->id,
                'performed_by' => $actorId, 'idempotency_key' => "delivery:{$order->id}:{$reservation->id}",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
