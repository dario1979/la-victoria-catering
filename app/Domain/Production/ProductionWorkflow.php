<?php

namespace App\Domain\Production;

use App\Domain\Alerts\AlertManager;
use App\Domain\Orders\OrderWorkflow;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductionWorkflow
{
    public function __construct(
        private readonly OrderWorkflow $orders,
        private readonly RecipeManager $recipes,
        private readonly ProductionRequirements $requirements,
        private readonly AlertManager $alerts,
    ) {}

    public function create(array $data, TenantContext $tenant): ProductionBatch
    {
        return DB::transaction(function () use ($data, $tenant): ProductionBatch {
            $order = Order::query()->lockForUpdate()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->findOrFail($data['order_id']);
            if ($order->status !== 'confirmed') {
                throw ValidationException::withMessages(['order_id' => ['Only confirmed orders can enter production.']]);
            }
            $recipe = Recipe::query()->where('status', 'approved')->with(['product', 'items.ingredient'])
                ->findOrFail($data['recipe_id']);
            if (! $order->items->contains('product_id', $recipe->product_id)) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['Recipe output product must be present in the order.'],
                ]);
            }

            return ProductionBatch::firstOrCreate(
                ['order_id' => $order->id],
                [
                    ...$data,
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                    'recipe_snapshot' => $this->recipes->snapshot($recipe),
                ]
            );
        });
    }

    public function start(ProductionBatch $batch, int $actorId): ProductionBatch
    {
        $result = DB::transaction(function () use ($batch, $actorId): ProductionBatch|array {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'in_progress') {
                return $batch;
            }
            if ($batch->status !== 'planned') {
                throw ValidationException::withMessages(['status' => ['Production order cannot be started.']]);
            }
            $requirements = $this->requirements->calculate($batch, true);
            if (! $requirements['can_produce']) {
                return ['missing' => collect($requirements['ingredients'])->where('can_produce', false)->values()->all()];
            }
            $this->orders->transition($batch->order, 'in_production', $actorId);
            $batch->update(['status' => 'in_progress', 'started_at' => now()]);

            return $batch->fresh('order');
        });
        if (is_array($result)) {
            $this->alerts->raise(
                $batch->organization_id,
                "production:{$batch->id}:ingredients-insufficient",
                'ProductionIngredientsInsufficient',
                'required ingredient availability is below requirement',
                'high',
                'production,inventory',
                'replenish ingredients or reschedule production',
                $batch->branch_id,
                ProductionBatch::class,
                $batch->id,
            );
            throw ValidationException::withMessages([
                'ingredients' => collect($result['missing'])->map(
                    fn (array $item) => "{$item['ingredient_name']}: missing {$item['missing_quantity']} {$item['normalized_unit']}"
                )->all(),
            ]);
        }

        return $result;
    }
}
