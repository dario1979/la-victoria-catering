<?php

namespace App\Domain\Production;

use App\Models\ProductionBatch;

final class ProductionTraceability
{
    public function forBatch(ProductionBatch $batch): array
    {
        $batch->load([
            'order',
            'recipe.product',
            'consumptions.lot.location',
            'producedLot.location',
            'producedLot.movements',
        ]);

        return [
            'production_order' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'order_id' => $batch->order_id,
                'branch_id' => $batch->branch_id,
                'planned_quantity' => $batch->getRawOriginal('planned_quantity'),
                'actual_yield' => $batch->getRawOriginal('actual_yield'),
                'waste_quantity' => $batch->getRawOriginal('waste_quantity'),
                'unit' => $batch->unit,
                'completed_by' => $batch->completed_by,
                'completed_at' => $batch->completed_at?->toISOString(),
            ],
            'recipe_snapshot' => $batch->recipe_snapshot,
            'consumed_lots' => $batch->consumptions->map(fn ($consumption) => [
                'ingredient_product_id' => $consumption->ingredient_product_id,
                'lot_id' => $consumption->inventory_lot_id,
                'lot_code' => $consumption->lot->code,
                'quantity' => $consumption->getRawOriginal('quantity'),
                'unit' => $consumption->unit,
                'stock_movement_id' => $consumption->stock_movement_id,
            ])->all(),
            'produced_lot' => $batch->producedLot ? [
                'id' => $batch->producedLot->id,
                'code' => $batch->producedLot->code,
                'product_id' => $batch->producedLot->product_id,
                'quantity' => $batch->producedLot->getRawOriginal('quantity'),
                'unit' => $batch->producedLot->unit,
                'location_id' => $batch->producedLot->location_id,
                'manufactured_at' => $batch->producedLot->manufactured_at?->toISOString(),
                'expires_at' => $batch->producedLot->expires_at?->toDateString(),
            ] : null,
        ];
    }
}
