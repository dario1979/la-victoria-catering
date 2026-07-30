<?php

namespace App\Support\Imports;

use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ImportExecutor
{
    public function __construct(private readonly ImportValidator $validator) {}

    /** @return list<array{type: string, id: int}> */
    public function execute(ImportBatch $batch): array
    {
        $path = Storage::disk(config('imports.disk'))->path($batch->storage_path);
        abort_unless(hash_file('sha256', $path) === $batch->file_sha256, 409, 'El archivo temporal cambió desde el dry-run.');
        $result = $this->validator->validate(
            $path,
            $batch->extension,
            $batch->type,
            $batch->mapping ?? [],
            $batch->organization_id,
            $batch->branch_id,
        );
        abort_if($result['errors'] !== [], 409, 'Los datos dejaron de ser válidos desde el dry-run.');

        return match ($batch->type) {
            'customers' => $this->customers($batch, $result['rows']),
            'products' => $this->products($batch, $result['rows']),
            'suppliers' => $this->suppliers($batch, $result['rows']),
            'supplier_products' => $this->supplierProducts($batch, $result['rows']),
            'locations' => $this->locations($batch, $result['rows']),
            'initial_stock' => $this->initialStock($batch, $result['rows']),
            'recipes' => $this->recipes($batch, $result['rows']),
            default => throw new RuntimeException('Unsupported import type.'),
        };
    }

    /** @param list<array<string, string>> $rows */
    private function customers(ImportBatch $batch, array $rows): array
    {
        return array_map(function (array $row) use ($batch): array {
            $model = Customer::create([
                'organization_id' => $batch->organization_id,
                'name' => $row['name'],
                'tax_id' => $row['tax_id'] ?: null,
                'tax_condition' => $row['tax_condition'] ?: null,
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'credit_limit' => $row['credit_limit'] ?: null,
                'active' => $this->boolean($row['active']),
            ]);

            return ['type' => Customer::class, 'id' => $model->id];
        }, $rows);
    }

    /** @param list<array<string, string>> $rows */
    private function products(ImportBatch $batch, array $rows): array
    {
        return array_map(function (array $row) use ($batch): array {
            $model = Product::create([
                'organization_id' => $batch->organization_id,
                'name' => $row['name'],
                'type' => $row['type'],
                'unit' => $row['unit'],
                'minimum_stock' => $row['minimum_stock'] ?: '0.000',
                'price' => $row['price'] ?: '0.00',
                'active' => $this->boolean($row['active']),
            ]);

            return ['type' => Product::class, 'id' => $model->id];
        }, $rows);
    }

    /** @param list<array<string, string>> $rows */
    private function suppliers(ImportBatch $batch, array $rows): array
    {
        return array_map(function (array $row) use ($batch): array {
            $model = Supplier::create([
                'organization_id' => $batch->organization_id,
                'trade_name' => $row['trade_name'],
                'legal_name' => $row['legal_name'] ?: null,
                'tax_id' => $row['tax_id'] ?: null,
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'contact_name' => $row['contact_name'] ?: null,
                'address' => $row['address'] ?: null,
                'payment_terms' => $row['payment_terms'] ?: null,
                'lead_time_days' => $row['lead_time_days'] ?: 0,
                'active' => $this->boolean($row['active']),
                'created_by' => $batch->created_by,
                'updated_by' => $batch->created_by,
            ]);

            return ['type' => Supplier::class, 'id' => $model->id];
        }, $rows);
    }

    /** @param list<array<string, string>> $rows */
    private function supplierProducts(ImportBatch $batch, array $rows): array
    {
        return array_map(function (array $row) use ($batch): array {
            $supplier = Supplier::query()->where('organization_id', $batch->organization_id)
                ->when($row['supplier_tax_id'] !== '',
                    fn ($query) => $query->where('tax_id', $row['supplier_tax_id']),
                    fn ($query) => $query->whereRaw('lower(trade_name) = ?', [mb_strtolower($row['supplier_name'])])
                )->sole();
            $product = $this->product($row['product_name'], $batch->organization_id);
            $model = SupplierProduct::create([
                'organization_id' => $batch->organization_id,
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'supplier_code' => $row['supplier_code'] ?: null,
                'purchase_unit' => $row['purchase_unit'],
                'conversion_factor' => $row['conversion_factor'],
                'minimum_quantity' => $row['minimum_quantity'] ?: '0.000',
                'lead_time_days' => $row['lead_time_days'] ?: 0,
                'preferred' => $this->boolean($row['preferred']),
                'active' => $this->boolean($row['active']),
                'created_by' => $batch->created_by,
                'updated_by' => $batch->created_by,
            ]);

            return ['type' => SupplierProduct::class, 'id' => $model->id];
        }, $rows);
    }

    /** @param list<array<string, string>> $rows */
    private function locations(ImportBatch $batch, array $rows): array
    {
        return array_map(function (array $row) use ($batch): array {
            $model = Location::create([
                'organization_id' => $batch->organization_id,
                'branch_id' => $batch->branch_id,
                'name' => $row['name'],
                'active' => $this->boolean($row['active']),
            ]);

            return ['type' => Location::class, 'id' => $model->id];
        }, $rows);
    }

    /** @param list<array<string, string>> $rows */
    private function initialStock(ImportBatch $batch, array $rows): array
    {
        $created = [];
        foreach ($rows as $index => $row) {
            $product = $this->product($row['product_name'], $batch->organization_id);
            $location = Location::query()->where('organization_id', $batch->organization_id)
                ->where('branch_id', $batch->branch_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($row['location_name'])])->sole();
            $lot = InventoryLot::create([
                'organization_id' => $batch->organization_id,
                'branch_id' => $batch->branch_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'code' => $row['lot_code'],
                'unit' => $row['unit'],
                'quantity' => $row['quantity'],
                'reserved_quantity' => '0.000',
                'manufactured_at' => $row['manufactured_on'] ?: null,
                'expires_at' => $row['expires_on'] ?: null,
                'status' => 'available',
                'created_by' => $batch->created_by,
            ]);
            $movement = StockMovement::create([
                'organization_id' => $batch->organization_id,
                'branch_id' => $batch->branch_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'inventory_lot_id' => $lot->id,
                'unit' => $row['unit'],
                'quantity' => $row['quantity'],
                'type' => 'adjustment',
                'reason' => 'initial_import',
                'reference_type' => ImportBatch::class,
                'reference_id' => $batch->id,
                'performed_by' => $batch->created_by,
                'idempotency_key' => "import:{$batch->uuid}:".($index + 2),
            ]);
            $created[] = ['type' => InventoryLot::class, 'id' => $lot->id];
            $created[] = ['type' => StockMovement::class, 'id' => $movement->id];
        }

        return $created;
    }

    /** @param list<array<string, string>> $rows */
    private function recipes(ImportBatch $batch, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[mb_strtolower($row['product_name']).'|'.$row['version']][] = $row;
        }
        $created = [];
        foreach ($groups as $recipeRows) {
            $first = $recipeRows[0];
            $product = $this->product($first['product_name'], $batch->organization_id);
            $recipe = Recipe::create([
                'product_id' => $product->id,
                'version' => $first['version'],
                'expected_yield' => $first['expected_yield'],
                'yield_unit' => $first['yield_unit'],
                'theoretical_waste_percent' => $first['waste_percent'] ?: null,
                'status' => 'draft',
            ]);
            foreach ($recipeRows as $row) {
                if ($row['expected_yield'] !== $first['expected_yield']
                    || $row['yield_unit'] !== $first['yield_unit']
                    || $row['waste_percent'] !== $first['waste_percent']) {
                    throw new RuntimeException('Recipe group fields changed after validation.');
                }
                $ingredient = $this->product($row['ingredient_name'], $batch->organization_id);
                $recipe->items()->create([
                    'ingredient_product_id' => $ingredient->id,
                    'quantity' => $row['ingredient_quantity'],
                    'unit' => $row['ingredient_unit'],
                ]);
            }
            $created[] = ['type' => Recipe::class, 'id' => $recipe->id];
        }

        return $created;
    }

    private function product(string $name, int $organizationId): Product
    {
        return Product::query()->where('organization_id', $organizationId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->sole();
    }

    private function boolean(string $value): bool
    {
        return $value === '' || in_array(mb_strtolower($value), ['true', '1', 'yes', 'si', 'sí'], true);
    }
}
