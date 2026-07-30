<?php

namespace App\Domain\Procurement;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderManager
{
    public function save(array $data, int $organizationId, int $branchId, int $actorId, ?PurchaseOrder $order = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $organizationId, $branchId, $actorId, $order): PurchaseOrder {
            if ($order) {
                abort_unless($order->organization_id === $organizationId && $order->branch_id === $branchId, 404);
                if ($order->status !== 'draft') {
                    throw ValidationException::withMessages(['status' => ['Only draft purchase orders can be edited.']]);
                }
            }
            $supplier = Supplier::query()->whereKey($data['supplier_id'])
                ->where('organization_id', $organizationId)->where('active', true)->firstOrFail();
            $prepared = [];
            $subtotalCents = 0;
            $taxCents = 0;
            foreach ($data['items'] as $index => $item) {
                $catalog = SupplierProduct::query()->with(['product', 'prices'])
                    ->whereKey($item['supplier_product_id'])
                    ->where('organization_id', $organizationId)
                    ->where('supplier_id', $supplier->id)
                    ->where('active', true)->first();
                if (! $catalog) {
                    throw ValidationException::withMessages(["items.{$index}.supplier_product_id" => ['The product is not active for the selected supplier.']]);
                }
                $price = $catalog->currentPrice();
                if (! array_key_exists('unit_price', $item) && ! $price) {
                    throw ValidationException::withMessages(["items.{$index}.unit_price" => ['The supplier product has no valid price.']]);
                }
                if ($price && $price->currency !== $data['currency'] && ! array_key_exists('unit_price', $item)) {
                    throw ValidationException::withMessages(["items.{$index}.unit_price" => ['Catalog price currency differs from the purchase order currency.']]);
                }
                $unitPrice = (string) ($item['unit_price'] ?? $price->price);
                $quantity = Decimal::toScaledInt($item['quantity'], 3);
                $priceCents = Decimal::toScaledInt($unitPrice, 2);
                if ($quantity !== 0 && $priceCents > intdiv(PHP_INT_MAX - 500, $quantity)) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => ['Line amount exceeds the supported range.']]);
                }
                $lineCents = intdiv(($quantity * $priceCents) + 500, 1000);
                $lineTax = Decimal::toScaledInt($item['tax_amount'] ?? 0, 2);
                if ($lineCents > 99_999_999_999_999 - $lineTax
                    || $subtotalCents > 99_999_999_999_999 - $lineCents
                    || $taxCents > 99_999_999_999_999 - $lineTax) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => ['Purchase order total exceeds the supported range.']]);
                }
                $subtotalCents += $lineCents;
                $taxCents += $lineTax;
                $prepared[] = [
                    'supplier_product_id' => $catalog->id, 'product_id' => $catalog->product_id,
                    'product_name' => $catalog->product->name, 'supplier_code' => $catalog->supplier_code,
                    'purchase_unit' => $catalog->purchase_unit, 'base_unit' => $catalog->product->unit,
                    'conversion_factor' => $catalog->conversion_factor, 'quantity' => $item['quantity'],
                    'received_quantity' => '0.000', 'unit_price' => $unitPrice,
                    'tax_amount' => Decimal::fromScaledInt($lineTax, 2),
                    'subtotal' => Decimal::fromScaledInt($lineCents, 2),
                    'total' => Decimal::fromScaledInt($lineCents + $lineTax, 2),
                ];
            }
            if ($subtotalCents > 99_999_999_999_999 - $taxCents) {
                throw ValidationException::withMessages(['items' => ['Purchase order total exceeds the supported range.']]);
            }
            $attributes = collect($data)->except('items')->all() + [
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'subtotal' => Decimal::fromScaledInt($subtotalCents, 2),
                'tax_total' => Decimal::fromScaledInt($taxCents, 2),
                'total' => Decimal::fromScaledInt($subtotalCents + $taxCents, 2),
                'updated_by' => $actorId,
            ];
            if ($order) {
                $order->update($attributes);
                $order->items()->delete();
            } else {
                $order = PurchaseOrder::create($attributes + [
                    'number' => 'PENDING-'.str()->uuid(), 'created_by' => $actorId,
                ]);
                $order->update(['number' => 'OC-'.now()->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
            }
            $order->items()->createMany($prepared);
            DB::table('audit_logs')->insert([
                'event' => $order->wasRecentlyCreated ? 'purchase_order.created' : 'purchase_order.updated',
                'subject_type' => PurchaseOrder::class, 'subject_id' => $order->id,
                'actor_type' => User::class, 'actor_id' => $actorId,
                'context' => json_encode(['supplier_id' => $supplier->id, 'total' => $order->total], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $order->fresh(['supplier', 'items']);
        });
    }
}
