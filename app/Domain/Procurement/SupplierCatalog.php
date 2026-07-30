<?php

namespace App\Domain\Procurement;

use App\Domain\Production\UnitConverter;
use App\Models\Product;
use App\Models\SupplierProduct;
use App\Models\SupplierProductPrice;
use App\Support\Decimal;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierCatalog
{
    public function __construct(private readonly UnitConverter $units) {}

    public function save(array $data, int $organizationId, int $actorId, ?SupplierProduct $catalogItem = null): SupplierProduct
    {
        return DB::transaction(function () use ($data, $organizationId, $actorId, $catalogItem): SupplierProduct {
            $product = Product::query()->whereKey($data['product_id'])
                ->where('organization_id', $organizationId)->firstOrFail();
            $this->units->assertCompatible($data['purchase_unit'], $product->unit);
            if ($catalogItem) {
                abort_unless($catalogItem->organization_id === $organizationId, 404);
                if ($catalogItem->supplier_id !== (int) $data['supplier_id']
                    || $catalogItem->product_id !== (int) $data['product_id']) {
                    throw ValidationException::withMessages(['product_id' => ['Supplier and product cannot change on an existing catalog item.']]);
                }
            }
            $duplicate = SupplierProduct::query()
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $data['supplier_id'])
                ->where('product_id', $data['product_id'])
                ->when($catalogItem, fn ($query) => $query->whereKeyNot($catalogItem->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['product_id' => ['This supplier already has a catalog entry for the product.']]);
            }
            $attributes = collect($data)->except(['price', 'currency', 'price_valid_from'])->all();
            $attributes += ['organization_id' => $organizationId, 'updated_by' => $actorId];
            if ($catalogItem) {
                $catalogItem->update($attributes);
            } else {
                $catalogItem = SupplierProduct::create($attributes + ['created_by' => $actorId]);
            }
            $current = $catalogItem->prices()->whereNull('valid_until')->lockForUpdate()->first();
            $priceChanged = ! $current
                || Decimal::toScaledInt((string) $current->price, 2) !== Decimal::toScaledInt($data['price'], 2)
                || $current->currency !== $data['currency']
                || $current->valid_from->toDateString() !== $data['price_valid_from'];
            if ($priceChanged) {
                if ($current && $data['price_valid_from'] <= $current->valid_from->toDateString()) {
                    throw ValidationException::withMessages(['price_valid_from' => ['A new price must start after the current price.']]);
                }
                if ($current) {
                    $current->update(['valid_until' => Date::parse($data['price_valid_from'])->subDay()->toDateString()]);
                }
                SupplierProductPrice::create([
                    'supplier_product_id' => $catalogItem->id,
                    'price' => $data['price'], 'currency' => $data['currency'],
                    'valid_from' => $data['price_valid_from'], 'created_by' => $actorId,
                ]);
            }

            return $catalogItem->fresh(['supplier', 'product', 'prices']);
        });
    }
}
