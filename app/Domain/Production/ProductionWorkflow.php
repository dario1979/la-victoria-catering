<?php

namespace App\Domain\Production;

use App\Domain\Orders\OrderWorkflow;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductionWorkflow
{
    public function __construct(private readonly OrderWorkflow $orders) {}

    public function create(array $data, TenantContext $tenant): ProductionBatch
    {
        return DB::transaction(function () use ($data, $tenant): ProductionBatch {
            $order = Order::query()->lockForUpdate()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->findOrFail($data['order_id']);
            if ($order->status !== 'confirmed') {
                throw ValidationException::withMessages(['order_id' => ['Only confirmed orders can enter production.']]);
            }

            return ProductionBatch::firstOrCreate(
                ['order_id' => $order->id],
                [...$data, 'organization_id' => $tenant->organization->id, 'branch_id' => $tenant->branch->id]
            );
        });
    }

    public function start(ProductionBatch $batch, int $actorId): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $actorId): ProductionBatch {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'in_progress') {
                return $batch;
            }
            if ($batch->status !== 'planned') {
                throw ValidationException::withMessages(['status' => ['Production order cannot be started.']]);
            }
            $this->orders->transition($batch->order, 'in_production', $actorId);
            $batch->update(['status' => 'in_progress', 'started_at' => now()]);

            return $batch->fresh('order');
        });
    }

    public function complete(ProductionBatch $batch, string $actualYield, string $waste, int $actorId): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $actualYield, $waste, $actorId): ProductionBatch {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'completed') {
                return $batch;
            }
            if ($batch->status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => ['Production order is not in progress.']]);
            }
            $this->orders->transition($batch->order, 'ready', $actorId);
            $batch->update([
                'status' => 'completed', 'actual_yield' => $actualYield,
                'waste_quantity' => $waste, 'completed_at' => now(),
            ]);

            return $batch->fresh('order');
        });
    }
}
