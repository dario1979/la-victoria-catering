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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportRollback
{
    /** @return array{removed: int, compensated_lots: int} */
    public function execute(ImportBatch $batch): array
    {
        abort_unless($batch->status === 'completed' && $batch->rolled_back_at === null, 409, 'El lote no admite rollback en su estado actual.');
        $records = $batch->created_records ?? [];
        if ($batch->type === 'initial_stock') {
            return $this->stock($batch, $records);
        }

        $models = [];
        foreach (array_reverse($records) as $record) {
            $model = $this->model($record);
            if ($model === null) {
                continue;
            }
            $this->assertUnchanged($batch, $model);
            $this->assertWithoutDependencies($model);
            $models[] = $model;
        }
        foreach ($models as $model) {
            $model->delete();
        }

        return ['removed' => count($models), 'compensated_lots' => 0];
    }

    /**
     * @param  list<array{type: string, id: int}>  $records
     * @return array{removed: int, compensated_lots: int}
     */
    private function stock(ImportBatch $batch, array $records): array
    {
        $lots = collect($records)->where('type', InventoryLot::class)
            ->map(fn (array $record) => InventoryLot::query()->find($record['id']))->filter();
        foreach ($lots as $lot) {
            $this->assertUnchanged($batch, $lot);
            if ((float) $lot->reserved_quantity !== 0.0 || $lot->movements()->count() !== 1) {
                throw ValidationException::withMessages([
                    'rollback' => ['El stock ya tuvo reservas o movimientos posteriores y no puede compensarse automáticamente.'],
                ]);
            }
        }
        foreach ($lots as $lot) {
            StockMovement::create([
                'organization_id' => $batch->organization_id,
                'branch_id' => $batch->branch_id,
                'product_id' => $lot->product_id,
                'location_id' => $lot->location_id,
                'inventory_lot_id' => $lot->id,
                'unit' => $lot->unit,
                'quantity' => '-'.$lot->quantity,
                'type' => 'adjustment',
                'reason' => 'initial_import_rollback',
                'reference_type' => ImportBatch::class,
                'reference_id' => $batch->id,
                'performed_by' => $batch->created_by,
                'idempotency_key' => "import-rollback:{$batch->uuid}:{$lot->id}",
            ]);
            $lot->update(['quantity' => '0.000', 'status' => 'depleted']);
        }

        return ['removed' => 0, 'compensated_lots' => $lots->count()];
    }

    /** @param array{type: string, id: int} $record */
    private function model(array $record): ?Model
    {
        $allowed = [
            Customer::class,
            Product::class,
            Supplier::class,
            SupplierProduct::class,
            Location::class,
            Recipe::class,
        ];
        if (! in_array($record['type'] ?? '', $allowed, true)) {
            return null;
        }

        return $record['type']::query()->find($record['id']);
    }

    private function assertUnchanged(ImportBatch $batch, Model $model): void
    {
        if ($model->updated_at && $batch->completed_at && $model->updated_at->gt($batch->completed_at->addSecond())) {
            throw ValidationException::withMessages([
                'rollback' => ['Un registro fue modificado después de la importación.'],
            ]);
        }
        if ((int) ($model->organization_id ?? $batch->organization_id) !== $batch->organization_id) {
            throw ValidationException::withMessages(['rollback' => ['El registro ya no pertenece al tenant del lote.']]);
        }
    }

    private function assertWithoutDependencies(Model $model): void
    {
        $blocked = match ($model::class) {
            Customer::class => DB::table('orders')->where('customer_id', $model->id)->exists()
                || DB::table('customer_account_entries')->where('customer_id', $model->id)->exists(),
            Product::class => DB::table('inventory_lots')->where('product_id', $model->id)->exists()
                || DB::table('order_items')->where('product_id', $model->id)->exists()
                || DB::table('recipes')->where('product_id', $model->id)->exists()
                || DB::table('recipe_items')->where('ingredient_product_id', $model->id)->exists()
                || DB::table('supplier_products')->where('product_id', $model->id)->exists(),
            Supplier::class => DB::table('purchase_orders')->where('supplier_id', $model->id)->exists()
                || DB::table('supplier_products')->where('supplier_id', $model->id)->exists(),
            SupplierProduct::class => DB::table('purchase_order_items')->where('supplier_product_id', $model->id)->exists(),
            Location::class => DB::table('inventory_lots')->where('location_id', $model->id)->exists()
                || DB::table('stock_movements')->where('location_id', $model->id)->exists(),
            Recipe::class => DB::table('production_batches')->where('recipe_id', $model->id)->exists(),
            default => true,
        };
        if ($blocked) {
            throw ValidationException::withMessages([
                'rollback' => ['Existen operaciones posteriores; el rollback automático fue rechazado.'],
            ]);
        }
    }
}
