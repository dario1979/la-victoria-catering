<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Production\CompleteProduction;
use App\Domain\Production\ProductionRequirements;
use App\Domain\Production\ProductionTraceability;
use App\Domain\Production\ProductionWorkflow;
use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ProductionOrderController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            ProductionBatch::query()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)
                ->with(['order', 'recipe.product']),
            $request,
            [],
            ['id' => 'id', 'status' => 'status', 'planned_quantity' => 'planned_quantity', 'started_at' => 'started_at', 'completed_at' => 'completed_at'],
            ['status' => 'status', 'order_id' => 'order_id', 'recipe_id' => 'recipe_id'],
            ['ID' => 'id', 'Pedido' => 'order_id', 'Producto' => 'recipe.product.name', 'Estado' => 'status', 'Planificado' => 'planned_quantity', 'Rendimiento' => 'actual_yield', 'Unidad' => 'unit', 'Inicio' => 'started_at', 'Fin' => 'completed_at'],
            'ordenes-produccion',
        );
    }

    public function store(Request $request, ProductionWorkflow $workflow, IdempotentAction $keys, TenantContext $tenant): JsonResponse
    {
        abort_unless($tenant->can('owner', 'admin', 'production'), 403);
        $data = $request->validate([
            'order_id' => ['required', Rule::exists('orders', 'id')->where(
                fn ($query) => $query->where('organization_id', $tenant->organization->id)
                    ->where('branch_id', $tenant->branch->id)
            )],
            'recipe_id' => ['required', Rule::exists('recipes', 'id')->where(
                fn ($query) => $query->where('status', 'approved')->whereExists(
                    fn ($products) => $products->selectRaw('1')->from('products')
                        ->whereColumn('products.id', 'recipes.product_id')
                        ->where('products.organization_id', $tenant->organization->id)
                )
            )],
            'planned_quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'unit' => ['required', 'string', 'max:24'],
        ]);
        [$body, $status, $replayed] = $keys->run(
            "organizations.{$tenant->organization->id}.production.create",
            $this->key($request), $data,
            fn () => [['data' => $workflow->create($data, $tenant)->toArray()], 201]
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(ProductionBatch $productionOrder, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($productionOrder, $tenant);

        return response()->json(['data' => $productionOrder->load('order')]);
    }

    public function start(Request $request, ProductionBatch $productionOrder, ProductionWorkflow $workflow, IdempotentAction $keys, TenantContext $tenant): JsonResponse
    {
        return $this->mutation($request, $productionOrder, $tenant, $keys, 'start',
            fn () => $workflow->start($productionOrder, $request->user()->id));
    }

    public function complete(
        Request $request,
        ProductionBatch $productionOrder,
        CompleteProduction $completion,
        IdempotentAction $keys,
        TenantContext $tenant,
    ): JsonResponse {
        $data = $request->validate([
            'actual_yield' => ['required', 'decimal:0,3', 'gte:0'],
            'unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'unit'])],
            'waste_quantity' => ['required', 'decimal:0,3', 'gte:0'],
            'destination_location_id' => ['required', 'integer'],
            'manufactured_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:manufactured_at'],
            'observations' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->mutation(
            $request,
            $productionOrder,
            $tenant,
            $keys,
            'complete',
            fn () => $completion->execute(
                $productionOrder, $data, $tenant, $request->user()->id
            ),
            $data
        );
    }

    public function requirements(
        ProductionBatch $productionOrder,
        ProductionRequirements $requirements,
        TenantContext $tenant,
    ): JsonResponse {
        $this->assertTenant($productionOrder, $tenant);

        return response()->json(['data' => $requirements->calculate($productionOrder)]);
    }

    public function traceability(
        ProductionBatch $productionOrder,
        ProductionTraceability $traceability,
        TenantContext $tenant,
    ): JsonResponse {
        $this->assertTenant($productionOrder, $tenant);

        return response()->json(['data' => $traceability->forBatch($productionOrder)]);
    }

    private function mutation(Request $request, ProductionBatch $batch, TenantContext $tenant, IdempotentAction $keys, string $operation, callable $action, array $data = []): JsonResponse
    {
        $this->assertTenant($batch, $tenant);
        abort_unless($tenant->can('owner', 'admin', 'production'), 403);
        [$body, $status, $replayed] = $keys->run(
            "organizations.{$tenant->organization->id}.production.{$batch->id}.{$operation}",
            $this->key($request), $data, fn () => [['data' => $action()->toArray()], 200]
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function assertTenant(ProductionBatch $batch, TenantContext $tenant): void
    {
        abort_unless($batch->organization_id === $tenant->organization->id && $batch->branch_id === $tenant->branch->id, 404);
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return $request->header('Idempotency-Key');
    }
}
