<?php

namespace App\Domain\Production;

use App\Domain\Alerts\AlertManager;
use App\Domain\Orders\OrderWorkflow;
use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\ProductionBatch;
use App\Support\Decimal;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CompleteProduction
{
    public function __construct(
        private readonly ProductionRequirements $requirements,
        private readonly UnitConverter $units,
        private readonly OrderWorkflow $orders,
        private readonly AlertManager $alerts,
    ) {}

    public function execute(
        ProductionBatch $batch,
        array $data,
        TenantContext $tenant,
        int $actorId,
    ): ProductionBatch {
        try {
            return DB::transaction(fn () => $this->completeLocked($batch, $data, $tenant, $actorId));
        } catch (InsufficientIngredients $exception) {
            $this->alerts->raise(
                $tenant->organization->id,
                "production:{$batch->id}:ingredients-insufficient",
                'ProductionIngredientsInsufficient',
                'required ingredient availability is below requirement',
                'high',
                'production,inventory,purchasing',
                'create or review a purchase order, or reschedule production',
                $tenant->branch->id,
                ProductionBatch::class,
                $batch->id,
            );
            throw ValidationException::withMessages([
                'ingredients' => collect($exception->ingredients)->map(
                    fn (array $item) => "{$item['ingredient_name']}: missing {$item['missing_quantity']} {$item['normalized_unit']}"
                )->all(),
            ]);
        } catch (ValidationException $exception) {
            $this->raiseFailure($batch, $tenant, $exception->getMessage());
            throw $exception;
        } catch (Throwable $exception) {
            $this->raiseFailure($batch, $tenant, $exception->getMessage());
            throw $exception;
        }
    }

    private function completeLocked(
        ProductionBatch $batch,
        array $data,
        TenantContext $tenant,
        int $actorId,
    ): ProductionBatch {
        $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
        abort_unless(
            $batch->organization_id === $tenant->organization->id
            && $batch->branch_id === $tenant->branch->id,
            404
        );
        if ($batch->status === 'completed') {
            throw ValidationException::withMessages(['status' => ['Production order is already completed.']]);
        }
        if ($batch->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => ['Production order is not in progress.']]);
        }
        $location = Location::query()->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)->where('active', true)
            ->findOrFail($data['destination_location_id']);
        $snapshot = $batch->recipe_snapshot;
        $productUnit = $snapshot['product']['unit'];
        $this->units->assertCompatible($data['unit'], $productUnit);
        $actualBase = $this->units->toBaseScaled($data['actual_yield'], $data['unit']);
        $outputScaled = $this->units->fromBaseScaledExact($actualBase, $productUnit);
        $requirements = $this->requirements->calculate($batch, true);
        $missing = collect($requirements['ingredients'])->where('can_produce', false)->values()->all();
        if ($missing !== []) {
            throw new InsufficientIngredients($missing);
        }

        foreach ($requirements['ingredients'] as $ingredient) {
            foreach ($ingredient['candidate_lots'] as $candidate) {
                $consumeScaled = Decimal::toScaledInt($candidate['consume_quantity'], 3);
                if ($consumeScaled <= 0) {
                    continue;
                }
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($candidate['lot_id']);
                $quantity = Decimal::toScaledInt($lot->quantity, 3);
                $reserved = Decimal::toScaledInt($lot->reserved_quantity, 3);
                if ($quantity - $consumeScaled < $reserved) {
                    throw new InsufficientIngredients([$ingredient]);
                }
                $lot->update(['quantity' => Decimal::fromScaledInt($quantity - $consumeScaled, 3)]);
                $movementId = DB::table('stock_movements')->insertGetId([
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                    'product_id' => $ingredient['ingredient_product_id'],
                    'location_id' => $lot->location_id,
                    'inventory_lot_id' => $lot->id,
                    'unit' => $lot->unit,
                    'quantity' => Decimal::fromScaledInt(-$consumeScaled, 3),
                    'type' => 'production_consumption',
                    'reason' => 'production_recipe_consumption',
                    'reference_type' => ProductionBatch::class,
                    'reference_id' => $batch->id,
                    'performed_by' => $actorId,
                    'idempotency_key' => "production:{$batch->id}:consume:{$ingredient['snapshot_item_index']}:{$lot->id}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('production_consumptions')->insert([
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                    'production_batch_id' => $batch->id,
                    'snapshot_item_index' => $ingredient['snapshot_item_index'],
                    'ingredient_product_id' => $ingredient['ingredient_product_id'],
                    'inventory_lot_id' => $lot->id,
                    'quantity' => Decimal::fromScaledInt($consumeScaled, 3),
                    'unit' => $lot->unit,
                    'stock_movement_id' => $movementId,
                    'performed_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($lot->expires_at && $lot->expires_at->lte(today()->addDays(3))) {
                    $this->alerts->raise(
                        $tenant->organization->id,
                        "production:{$batch->id}:near-expiry-lot:{$lot->id}",
                        'NearExpiryLotUsed',
                        'ingredient lot expires within three days',
                        'warning',
                        'production,inventory',
                        'verify finished product expiry and prioritize dispatch',
                        $tenant->branch->id,
                        InventoryLot::class,
                        $lot->id,
                    );
                }
            }
        }

        $lotCode = sprintf('PRD-%04d-%08d', $tenant->branch->id, $batch->id);
        $producedLot = InventoryLot::create([
            'organization_id' => $tenant->organization->id,
            'branch_id' => $tenant->branch->id,
            'product_id' => $snapshot['product']['id'],
            'location_id' => $location->id,
            'code' => $lotCode,
            'unit' => $productUnit,
            'quantity' => Decimal::fromScaledInt($outputScaled, 3),
            'reserved_quantity' => '0.000',
            'manufactured_at' => $data['manufactured_at'],
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'available',
            'production_batch_id' => $batch->id,
            'recipe_id' => $snapshot['recipe_id'],
            'recipe_version' => $snapshot['version'],
            'created_by' => $actorId,
        ]);
        DB::table('stock_movements')->insert([
            'organization_id' => $tenant->organization->id,
            'branch_id' => $tenant->branch->id,
            'product_id' => $producedLot->product_id,
            'location_id' => $producedLot->location_id,
            'inventory_lot_id' => $producedLot->id,
            'unit' => $producedLot->unit,
            'quantity' => $producedLot->quantity,
            'type' => 'production_output',
            'reason' => 'production_completed',
            'reference_type' => ProductionBatch::class,
            'reference_id' => $batch->id,
            'performed_by' => $actorId,
            'idempotency_key' => "production:{$batch->id}:output",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->orders->transition($batch->order, 'ready', $actorId);
        $batch->update([
            'status' => 'completed',
            'actual_yield' => $data['actual_yield'],
            'waste_quantity' => $data['waste_quantity'],
            'unit' => $data['unit'],
            'destination_location_id' => $location->id,
            'manufactured_at' => $data['manufactured_at'],
            'expires_at' => $data['expires_at'] ?? null,
            'observations' => $data['observations'] ?? null,
            'completed_at' => now(),
            'completed_by' => $actorId,
        ]);
        $this->resolveShortageAlert($batch);
        $this->raisePerformanceAlerts($batch, $data, $tenant);

        return $batch->fresh(['order', 'recipe', 'consumptions.lot', 'producedLot']);
    }

    private function resolveShortageAlert(ProductionBatch $batch): void
    {
        $alert = DB::table('alerts')->where('organization_id', $batch->organization_id)
            ->where('deduplication_key', "production:{$batch->id}:ingredients-insufficient")
            ->where('status', 'open')->first();
        if ($alert) {
            $this->alerts->resolve($alert->id);
        }
    }

    private function raisePerformanceAlerts(ProductionBatch $batch, array $data, TenantContext $tenant): void
    {
        $planned = $this->units->toBaseScaled($batch->planned_quantity, $batch->unit);
        $actual = $this->units->toBaseScaled($data['actual_yield'], $data['unit']);
        if ($actual * 100 < $planned * 90) {
            $this->alerts->raise(
                $tenant->organization->id, "production:{$batch->id}:low-yield",
                'ProductionYieldBelowThreshold', 'actual yield is below 90% of planned',
                'warning', 'production', 'review recipe execution and batch record',
                $tenant->branch->id, ProductionBatch::class, $batch->id
            );
        }
        $theoretical = $batch->recipe_snapshot['theoretical_waste_percent'] ?? null;
        if ($theoretical !== null) {
            $waste = $this->units->toBaseScaled($data['waste_quantity'], $data['unit']);
            $thresholdHundredths = Decimal::toScaledInt($theoretical, 2);
            if ($waste * 10000 > max(1, $planned) * $thresholdHundredths) {
                $this->alerts->raise(
                    $tenant->organization->id, "production:{$batch->id}:high-waste",
                    'ProductionWasteAboveThreshold', 'actual waste exceeds recipe threshold',
                    'warning', 'production', 'review waste cause and corrective action',
                    $tenant->branch->id, ProductionBatch::class, $batch->id
                );
            }
        }
        if (($data['expires_at'] ?? null) === null) {
            $this->alerts->raise(
                $tenant->organization->id, "production:{$batch->id}:missing-expiry",
                'ProducedLotMissingExpiry', 'produced lot has no expiry date',
                'warning', 'production,inventory', 'define and record lot expiry',
                $tenant->branch->id, ProductionBatch::class, $batch->id
            );
        }
    }

    private function raiseFailure(ProductionBatch $batch, TenantContext $tenant, string $reason): void
    {
        $this->alerts->raise(
            $tenant->organization->id, "production:{$batch->id}:completion-failed",
            'ProductionCompletionFailed', 'production completion transaction failed',
            'high', 'production,administration', 'review failure and retry safely',
            $tenant->branch->id, ProductionBatch::class, $batch->id
        );
    }
}
