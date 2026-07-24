<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\AdjustStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustStockRequest;
use App\Models\InventoryLot;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LotController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $lots = InventoryLot::query()
            ->whereHas('location', fn ($query) => $query
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($lots);
    }

    public function show(InventoryLot $lot, TenantContext $tenant): JsonResponse
    {
        $lot->load('location');
        abort_unless(
            $lot->location->organization_id === $tenant->organization->id
            && $lot->location->branch_id === $tenant->branch->id,
            404
        );
        $movements = $lot->movements()->latest('id')->paginate(25);

        return response()->json(['data' => $lot, 'movements' => $movements]);
    }

    public function adjust(
        AdjustStockRequest $request,
        AdjustStock $action,
        TenantContext $tenant,
    ): JsonResponse {
        abort_unless($tenant->can('owner', 'admin', 'inventory'), 403);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        $lot = $action->execute(
            $request->validated(), $tenant, $request->user()->id, $request->header('Idempotency-Key')
        );

        return response()->json(['data' => $lot], 201);
    }
}
