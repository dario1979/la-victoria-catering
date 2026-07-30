<?php

namespace App\Domain\Procurement;

use App\Domain\Alerts\AlertManager;
use App\Domain\Finance\AccountsPayableManager;
use App\Domain\Production\ProductionRequirements;
use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceivePurchaseOrder
{
    public function __construct(
        private readonly ProcurementQuantity $quantities,
        private readonly PurchaseOrderWorkflow $workflow,
        private readonly AlertManager $alerts,
        private readonly AccountsPayableManager $accountsPayable,
        private readonly ProductionRequirements $productionRequirements,
    ) {}

    public function execute(
        PurchaseOrder $order,
        array $data,
        int $organizationId,
        int $branchId,
        int $actorId,
    ): PurchaseReceipt {
        $alerts = [];
        $receipt = DB::transaction(function () use (
            $order, $data, $organizationId, $branchId, $actorId, &$alerts
        ): PurchaseReceipt {
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            abort_unless($order->organization_id === $organizationId && $order->branch_id === $branchId, 404);
            if (! in_array($order->status, ['sent', 'partially_received'], true)) {
                throw ValidationException::withMessages(['status' => ['Only sent purchase orders can be received.']]);
            }
            $receipt = PurchaseReceipt::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'purchase_order_id' => $order->id, 'number' => 'PENDING-'.str()->uuid(),
                'received_at' => $data['received_at'], 'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            $receipt->update(['number' => 'RC-'.now()->format('Y').'-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT)]);
            $lockedItems = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)
                ->whereIn('id', collect($data['items'])->pluck('purchase_order_item_id'))
                ->lockForUpdate()->get()->keyBy('id');
            foreach ($data['items'] as $index => $line) {
                $orderItem = $lockedItems->get((int) $line['purchase_order_item_id']);
                if (! $orderItem) {
                    throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => ['The item does not belong to this purchase order.']]);
                }
                $received = Decimal::toScaledInt($line['received_quantity'], 3);
                $accepted = Decimal::toScaledInt($line['accepted_quantity'], 3);
                $rejected = Decimal::toScaledInt($line['rejected_quantity'], 3);
                if ($received !== $accepted + $rejected) {
                    throw ValidationException::withMessages(["items.{$index}.received_quantity" => ['Received quantity must equal accepted plus rejected quantity.']]);
                }
                if ($rejected > 0 && blank($line['discrepancy_type'] ?? null)) {
                    throw ValidationException::withMessages(["items.{$index}.discrepancy_type" => ['A discrepancy type is required when units are rejected.']]);
                }
                $storedQuantities = DB::table('purchase_order_items')->where('id', $orderItem->id)
                    ->first(['quantity', 'received_quantity']);
                $ordered = Decimal::toScaledInt((string) $storedQuantities->quantity, 3);
                $previouslyAccepted = Decimal::toScaledInt((string) $storedQuantities->received_quantity, 3);
                if ($accepted > $ordered - $previouslyAccepted) {
                    throw ValidationException::withMessages(["items.{$index}.accepted_quantity" => ['Accepted quantity exceeds the pending ordered quantity.']]);
                }
                $location = Location::query()->whereKey($line['location_id'])
                    ->where('organization_id', $organizationId)->where('branch_id', $branchId)
                    ->where('active', true)->firstOrFail();
                $product = Product::query()->whereKey($orderItem->product_id)
                    ->where('organization_id', $organizationId)->firstOrFail();
                $baseQuantity = $this->quantities->toBase(
                    $line['accepted_quantity'], $orderItem->purchase_unit,
                    $orderItem->base_unit, $orderItem->conversion_factor
                );
                $lot = null;
                $movement = null;
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id, 'product_id' => $product->id,
                    'location_id' => $location->id, 'received_quantity' => $line['received_quantity'],
                    'accepted_quantity' => $line['accepted_quantity'], 'rejected_quantity' => $line['rejected_quantity'],
                    'discrepancy_type' => $line['discrepancy_type'] ?? null,
                    'discrepancy_reason' => $line['discrepancy_reason'] ?? null,
                    'lot_code' => $line['lot_code'], 'manufactured_at' => $line['manufactured_at'] ?? null,
                    'expires_at' => $line['expires_at'] ?? null, 'actual_unit_cost' => $line['actual_unit_cost'] ?? null,
                ]);
                if ($accepted > 0) {
                    $lot = InventoryLot::query()->where('product_id', $product->id)
                        ->where('location_id', $location->id)->where('code', $line['lot_code'])
                        ->lockForUpdate()->first();
                    if ($lot) {
                        abort_unless(
                            $lot->organization_id === $organizationId
                            && $lot->branch_id === $branchId
                            && $lot->unit === $product->unit,
                            422,
                            'Existing lot does not match the active tenant or product unit.'
                        );
                        if (! in_array($lot->status, ['available', 'depleted'], true)) {
                            throw ValidationException::withMessages(["items.{$index}.lot_code" => ['Blocked or expired lots cannot receive new stock.']]);
                        }
                        if (($lot->expires_at?->toDateString()) !== ($line['expires_at'] ?? null)) {
                            throw ValidationException::withMessages(["items.{$index}.expires_at" => ['Existing lot expiration must match the receipt.']]);
                        }
                        $incomingManufacturedAt = isset($line['manufactured_at'])
                            ? Date::parse($line['manufactured_at'])->utc()->format('Y-m-d H:i:s')
                            : null;
                        $storedManufacturedAt = $lot->manufactured_at?->utc()->format('Y-m-d H:i:s');
                        if ($storedManufacturedAt !== $incomingManufacturedAt) {
                            throw ValidationException::withMessages(["items.{$index}.manufactured_at" => ['Existing lot manufacture time must match the receipt.']]);
                        }
                    } else {
                        $lot = InventoryLot::create([
                            'organization_id' => $organizationId, 'branch_id' => $branchId,
                            'product_id' => $product->id, 'location_id' => $location->id,
                            'code' => $line['lot_code'], 'unit' => $product->unit,
                            'quantity' => '0.000', 'reserved_quantity' => '0.000',
                            'manufactured_at' => $line['manufactured_at'] ?? null,
                            'expires_at' => $line['expires_at'] ?? null,
                            'status' => 'available', 'created_by' => $actorId,
                        ]);
                    }
                    $current = Decimal::toScaledInt(
                        (string) DB::table('inventory_lots')->where('id', $lot->id)->value('quantity'),
                        3
                    );
                    $delta = Decimal::toScaledInt($baseQuantity, 3);
                    if ($current > 99_999_999_999_999 - $delta) {
                        throw ValidationException::withMessages(["items.{$index}.accepted_quantity" => ['Lot quantity exceeds the supported range.']]);
                    }
                    $lot->update(['quantity' => Decimal::fromScaledInt($current + $delta, 3), 'status' => 'available']);
                    $movement = StockMovement::create([
                        'organization_id' => $organizationId, 'branch_id' => $branchId,
                        'product_id' => $product->id, 'location_id' => $location->id,
                        'inventory_lot_id' => $lot->id, 'unit' => $product->unit,
                        'quantity' => $baseQuantity, 'type' => 'purchase_receipt',
                        'reason' => "Receipt {$receipt->number}",
                        'reference_type' => PurchaseReceipt::class, 'reference_id' => $receipt->id,
                        'performed_by' => $actorId,
                        'idempotency_key' => "purchase-receipt:{$receipt->id}:{$orderItem->id}",
                    ]);
                    $receiptItem->update(['inventory_lot_id' => $lot->id, 'stock_movement_id' => $movement->id]);
                    DB::table('purchase_order_items')->where('id', $orderItem->id)->update([
                        'received_quantity' => Decimal::fromScaledInt($previouslyAccepted + $accepted, 3),
                        'updated_at' => now(),
                    ]);
                }
                if ($rejected > 0 || $received > $ordered - $previouslyAccepted) {
                    $alerts[] = [
                        "purchase-receipt:{$receipt->id}:item:{$orderItem->id}:discrepancy",
                        'PurchaseReceiptDiscrepancy',
                        "received={$line['received_quantity']}; accepted={$line['accepted_quantity']}; rejected={$line['rejected_quantity']}",
                        'high',
                        'Review the discrepancy with the supplier and decide whether to replace or credit it.',
                        $receiptItem->id,
                    ];
                }
            }
            $this->workflow->synchronizeReceiptStatus($order);
            DB::table('audit_logs')->insert([
                'event' => 'purchase_receipt.created',
                'subject_type' => PurchaseReceipt::class, 'subject_id' => $receipt->id,
                'actor_type' => User::class, 'actor_id' => $actorId,
                'context' => json_encode(['purchase_order_id' => $order->id], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->accountsPayable->fromReceipt($receipt);

            return $receipt->fresh(['order.supplier', 'items']);
        });
        foreach ($alerts as [$key, $event, $condition, $severity, $action, $relatedId]) {
            $this->alerts->raise(
                $organizationId, $key, $event, $condition, $severity, 'purchasing',
                $action, $branchId, PurchaseReceiptItem::class, $relatedId
            );
        }
        $order = $receipt->order;
        if ($order->status === 'partially_received') {
            $this->alerts->raise(
                $organizationId, "purchase-order:{$order->id}:partial", 'PurchaseOrderPartiallyReceived',
                'The purchase order still has pending accepted quantities.', 'warning', 'purchasing',
                'Review pending quantities and schedule the next receipt.', $branchId, PurchaseOrder::class, $order->id
            );
        } else {
            $this->alerts->resolveByKey($organizationId, "purchase-order:{$order->id}:partial", $actorId);
        }
        ProductionBatch::query()->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)->whereNotNull('recipe_snapshot')
            ->whereIn('status', ['planned', 'in_progress'])
            ->orderBy('id')->each(function (ProductionBatch $batch) use ($organizationId, $actorId): void {
                try {
                    $available = $this->productionRequirements->calculate($batch)['can_produce'];
                } catch (\Throwable) {
                    return;
                }
                if ($available) {
                    $this->alerts->resolveByKey(
                        $organizationId,
                        "production:{$batch->id}:ingredients-insufficient",
                        $actorId
                    );
                }
            });

        return $receipt;
    }
}
