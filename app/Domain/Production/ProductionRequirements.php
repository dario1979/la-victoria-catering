<?php

namespace App\Domain\Production;

use App\Models\InventoryLot;
use App\Models\ProductionBatch;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;

final class ProductionRequirements
{
    public function __construct(private readonly UnitConverter $units) {}

    public function calculate(ProductionBatch $batch, bool $lockLots = false): array
    {
        $snapshot = $batch->recipe_snapshot;
        $this->units->assertCompatible($batch->unit, $snapshot['yield_unit']);
        $plannedBase = $this->units->toBaseScaled($batch->planned_quantity, $batch->unit);
        $yieldBase = $this->units->toBaseScaled($snapshot['expected_yield'], $snapshot['yield_unit']);
        $ingredients = [];

        foreach ($snapshot['items'] as $item) {
            $ingredientBase = $this->units->toBaseScaled($item['quantity'], $item['unit']);
            $requiredBase = $this->units->proportionalCeil($ingredientBase, $plannedBase, $yieldBase);
            $query = InventoryLot::query()
                ->where('product_id', $item['ingredient_product_id'])
                ->where('status', 'available')
                ->whereHas('location', fn (Builder $location) => $location
                    ->where('organization_id', $batch->organization_id)
                    ->where('branch_id', $batch->branch_id)
                    ->where('active', true))
                ->where(fn (Builder $expiry) => $expiry
                    ->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id');
            if ($lockLots) {
                $query->lockForUpdate();
            }
            $remaining = $requiredBase;
            $availableBase = 0;
            $candidates = [];
            foreach ($query->get() as $lot) {
                $availableLotScaled = Decimal::toScaledInt($lot->quantity, 3)
                    - Decimal::toScaledInt($lot->reserved_quantity, 3);
                if ($availableLotScaled <= 0) {
                    continue;
                }
                $lotAvailableBase = $this->units->toBaseScaled(
                    Decimal::fromScaledInt($availableLotScaled, 3), $lot->unit
                );
                $availableBase += $lotAvailableBase;
                $requestedBase = min($remaining, $lotAvailableBase);
                $consumeLotScaled = $requestedBase > 0
                    ? $this->units->fromBaseScaledCeil($requestedBase, $lot->unit)
                    : 0;
                $consumeBase = $consumeLotScaled > 0
                    ? $this->units->toBaseScaled(Decimal::fromScaledInt($consumeLotScaled, 3), $lot->unit)
                    : 0;
                $remaining = max(0, $remaining - $consumeBase);
                $candidates[] = [
                    'lot_id' => $lot->id,
                    'code' => $lot->code,
                    'expires_at' => $lot->expires_at?->toDateString(),
                    'available_quantity' => Decimal::fromScaledInt($availableLotScaled, 3),
                    'available_unit' => $lot->unit,
                    'consume_quantity' => Decimal::fromScaledInt($consumeLotScaled, 3),
                    'consume_unit' => $lot->unit,
                ];
            }
            $requiredScaled = $this->units->fromBaseScaledCeil($requiredBase, $item['unit']);
            $availableScaled = $this->units->fromBaseScaledCeil($availableBase, $item['unit']);
            $missingScaled = $this->units->fromBaseScaledCeil(max(0, $requiredBase - $availableBase), $item['unit']);
            $ingredients[] = [
                'snapshot_item_index' => $item['index'],
                'ingredient_product_id' => $item['ingredient_product_id'],
                'ingredient_name' => $item['ingredient_name'],
                'required_quantity' => Decimal::fromScaledInt($requiredScaled, 3),
                'normalized_unit' => $item['unit'],
                'available_quantity' => Decimal::fromScaledInt($availableScaled, 3),
                'missing_quantity' => Decimal::fromScaledInt($missingScaled, 3),
                'can_produce' => $availableBase >= $requiredBase,
                'candidate_lots' => $candidates,
            ];
        }

        return [
            'production_order_id' => $batch->id,
            'recipe' => [
                'id' => $snapshot['recipe_id'],
                'version' => $snapshot['version'],
                'product' => $snapshot['product'],
                'expected_yield' => $snapshot['expected_yield'],
                'yield_unit' => $snapshot['yield_unit'],
            ],
            'planned_quantity' => $batch->planned_quantity,
            'planned_unit' => $batch->unit,
            'ingredients' => $ingredients,
            'can_produce' => collect($ingredients)->every('can_produce'),
        ];
    }
}
