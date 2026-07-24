<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\AdjustStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustStockRequest;
use App\Models\InventoryLot;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LotController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        $query = InventoryLot::query()
            ->whereHas('location', fn ($query) => $query
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id))
            ->with(['product', 'location']);

        return $table->respond(
            $query,
            $request,
            ['code'],
            ['id' => 'id', 'code' => 'code', 'quantity' => 'quantity', 'expires_at' => 'expires_at', 'status' => 'status'],
            ['product_id' => 'product_id', 'location_id' => 'location_id', 'status' => 'status'],
            ['ID' => 'id', 'Código' => 'code', 'Producto' => 'product.name', 'Ubicación' => 'location.name', 'Cantidad' => 'quantity', 'Reservado' => 'reserved_quantity', 'Unidad' => 'unit', 'Vencimiento' => 'expires_at', 'Estado' => 'status'],
            'lotes',
            'expires_at',
            'asc',
        );
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
        $consumedBy = $lot->productionConsumptions()
            ->with(['productionBatch.producedLot', 'productionBatch.order'])
            ->latest('id')->get();

        return response()->json([
            'data' => $lot,
            'movements' => $movements,
            'production_traceability' => $consumedBy,
        ]);
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
