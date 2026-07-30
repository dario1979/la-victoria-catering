<?php

namespace App\Domain\Inventory;

use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdjustStock
{
    public function execute(array $data, TenantContext $tenant, int $userId, string $idempotencyKey): InventoryLot
    {
        return DB::transaction(function () use ($data, $tenant, $userId, $idempotencyKey): InventoryLot {
            $existing = DB::table('stock_movements')
                ->where('organization_id', $tenant->organization->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return InventoryLot::findOrFail($existing->inventory_lot_id);
            }
            $lot = isset($data['lot_id'])
                ? InventoryLot::query()->lockForUpdate()->findOrFail($data['lot_id'])
                : $this->createLot($data, $tenant, $userId);
            $product = Product::whereKey($lot->product_id)
                ->where('organization_id', $tenant->organization->id)->firstOrFail();
            $location = Location::whereKey($lot->location_id)
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->firstOrFail();
            abort_unless($product->unit === $lot->unit, 422, 'Lot unit does not match product unit.');

            $current = Decimal::toScaledInt($lot->getRawOriginal('quantity'), 3);
            $delta = Decimal::toScaledInt($data['quantity'], 3);
            $next = $current + $delta;
            $reserved = Decimal::toScaledInt($lot->getRawOriginal('reserved_quantity'), 3);
            if ($next < $reserved) {
                throw ValidationException::withMessages(['quantity' => ['Adjustment would reduce stock below active reservations.']]);
            }
            $lot->update(['quantity' => Decimal::fromScaledInt($next, 3)]);
            DB::table('stock_movements')->insert([
                'organization_id' => $tenant->organization->id,
                'branch_id' => $tenant->branch->id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'inventory_lot_id' => $lot->id,
                'unit' => $lot->unit,
                'quantity' => Decimal::fromScaledInt($delta, 3),
                'type' => $data['type'],
                'reason' => $data['reason'],
                'reference_type' => 'manual_adjustment',
                'performed_by' => $userId,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $lot->fresh();
        });
    }

    private function createLot(array $data, TenantContext $tenant, int $userId): InventoryLot
    {
        Product::whereKey($data['product_id'])->where('organization_id', $tenant->organization->id)->firstOrFail();
        Location::whereKey($data['location_id'])->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)->firstOrFail();

        return InventoryLot::create([
            'organization_id' => $tenant->organization->id,
            'branch_id' => $tenant->branch->id,
            'product_id' => $data['product_id'],
            'location_id' => $data['location_id'],
            'code' => $data['code'],
            'unit' => $data['unit'],
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'available',
            'created_by' => $userId,
        ]);
    }
}
