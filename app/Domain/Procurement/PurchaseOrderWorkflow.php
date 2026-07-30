<?php

namespace App\Domain\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderTransition;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderWorkflow
{
    private const TRANSITIONS = [
        'draft' => ['approved', 'cancelled'],
        'approved' => ['sent', 'cancelled'],
        'sent' => ['cancelled'],
        'partially_received' => ['cancelled'],
        'received' => [],
        'cancelled' => [],
    ];

    public function allowedTransitions(PurchaseOrder $order): array
    {
        return self::TRANSITIONS[$order->status] ?? [];
    }

    public function transition(PurchaseOrder $order, string $target, int $actorId, ?string $reason = null): PurchaseOrder
    {
        $rejection = null;
        $result = DB::transaction(function () use ($order, $target, $actorId, $reason, &$rejection): PurchaseOrder {
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $allowed = $this->allowedTransitions($order);
            if (! in_array($target, $allowed, true)) {
                PurchaseOrderTransition::create([
                    'purchase_order_id' => $order->id, 'from_status' => $order->status,
                    'to_status' => $target, 'actor_id' => $actorId, 'accepted' => false,
                    'reason' => 'invalid_transition',
                ]);
                $rejection = ['from' => $order->status, 'allowed' => $allowed];

                return $order;
            }
            if ($target === 'approved' && $order->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['A purchase order must contain at least one item before approval.']]);
            }
            $from = $order->status;
            $changes = ['status' => $target, 'updated_by' => $actorId];
            if ($target === 'approved') {
                $changes += ['approved_by' => $actorId, 'approved_at' => now()];
            } elseif ($target === 'sent') {
                $changes += ['sent_by' => $actorId, 'sent_at' => now()];
            } elseif ($target === 'cancelled') {
                $changes += ['cancelled_by' => $actorId, 'cancelled_at' => now()];
            }
            $order->update($changes);
            PurchaseOrderTransition::create([
                'purchase_order_id' => $order->id, 'from_status' => $from,
                'to_status' => $target, 'actor_id' => $actorId,
                'accepted' => true, 'reason' => $reason,
            ]);
            DB::table('audit_logs')->insert([
                'event' => 'purchase_order.transitioned',
                'subject_type' => PurchaseOrder::class, 'subject_id' => $order->id,
                'actor_type' => User::class, 'actor_id' => $actorId,
                'context' => json_encode(['from' => $from, 'to' => $target, 'reason' => $reason], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $order->fresh(['supplier', 'items']);
        });
        if ($rejection) {
            throw ValidationException::withMessages([
                'status' => ["Transition {$rejection['from']} → {$target} is not allowed.", 'allowed' => $rejection['allowed']],
            ]);
        }

        return $result;
    }

    public function synchronizeReceiptStatus(PurchaseOrder $order): PurchaseOrder
    {
        $items = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->get();
        $hasReceipts = $items->contains(
            fn ($item) => Decimal::toScaledInt((string) $item->received_quantity, 3) > 0
        );
        $complete = $items->every(
            fn ($item) => Decimal::toScaledInt((string) $item->received_quantity, 3)
                >= Decimal::toScaledInt((string) $item->quantity, 3)
        );
        $status = $complete ? 'received' : ($hasReceipts ? 'partially_received' : 'sent');
        if ($order->status !== $status) {
            $from = $order->status;
            $order->update(['status' => $status]);
            PurchaseOrderTransition::create([
                'purchase_order_id' => $order->id, 'from_status' => $from,
                'to_status' => $status, 'accepted' => true, 'reason' => 'receipt',
            ]);
        }

        return $order->fresh(['supplier', 'items', 'receipts.items']);
    }
}
