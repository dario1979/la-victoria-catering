<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertManager;
use App\Domain\Procurement\ProcurementAccess;
use App\Domain\Procurement\PurchaseOrderManager;
use App\Domain\Procurement\PurchaseOrderWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseOrderController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view-orders');

        return $table->respond(
            PurchaseOrder::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->with(['supplier', 'items']),
            $request,
            [
                'number',
                fn ($query, $search, $operator) => $query
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('trade_name', $operator, "%{$search}%")),
            ],
            [
                'id' => 'id', 'number' => 'number', 'status' => 'status',
                'ordered_at' => 'ordered_at', 'expected_at' => 'expected_at',
                'total' => 'total', 'created_at' => 'created_at',
            ],
            ['status' => 'status', 'supplier_id' => 'supplier_id', 'currency' => 'currency'],
            [
                'ID' => 'id', 'Número' => 'number',
                'Proveedor' => fn ($row) => $row->supplier->trade_name,
                'Estado' => 'status', 'Emisión' => 'ordered_at', 'Entrega esperada' => 'expected_at',
                'Moneda' => 'currency', 'Subtotal' => 'subtotal', 'Impuestos' => 'tax_total', 'Total' => 'total',
            ],
            'ordenes-compra',
        );
    }

    public function store(
        SavePurchaseOrderRequest $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        PurchaseOrderManager $manager,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-orders');
        $data = $request->validated();
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.branches.{$tenant->branch->id}.purchase-orders.create",
            $this->key($request),
            $data,
            fn () => [['data' => $manager->save(
                $data, $tenant->organization->id, $tenant->branch->id, $request->user()->id
            )->toArray()], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(
        PurchaseOrder $purchaseOrder,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view-orders');
        $this->assertTenant($purchaseOrder, $tenant);

        return response()->json([
            'data' => $purchaseOrder->load(['supplier', 'items.product', 'receipts.items', 'transitions']),
        ]);
    }

    public function update(
        SavePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        TenantContext $tenant,
        ProcurementAccess $access,
        PurchaseOrderManager $manager,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-orders');
        $this->assertTenant($purchaseOrder, $tenant);
        $order = $manager->save(
            $request->validated(), $tenant->organization->id, $tenant->branch->id,
            $request->user()->id, $purchaseOrder
        );

        return response()->json(['data' => $order]);
    }

    public function allowedTransitions(
        PurchaseOrder $purchaseOrder,
        TenantContext $tenant,
        ProcurementAccess $access,
        PurchaseOrderWorkflow $workflow,
    ): JsonResponse {
        $access->authorize($tenant, 'view-orders');
        $this->assertTenant($purchaseOrder, $tenant);

        return response()->json(['data' => $workflow->allowedTransitions($purchaseOrder)]);
    }

    public function transition(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TenantContext $tenant,
        ProcurementAccess $access,
        PurchaseOrderWorkflow $workflow,
        IdempotentAction $idempotency,
        AlertManager $alerts,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-orders');
        $this->assertTenant($purchaseOrder, $tenant);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'sent', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.purchase-orders.{$purchaseOrder->id}.transition",
            $this->key($request),
            $data,
            fn () => [['data' => $workflow->transition(
                $purchaseOrder, $data['status'], $request->user()->id, $data['reason'] ?? null
            )->toArray()], 200],
        );
        if (! $replayed && $data['status'] === 'approved') {
            $alerts->raise(
                $tenant->organization->id, "purchase-order:{$purchaseOrder->id}:approved-not-sent",
                'PurchaseOrderApprovedNotSent', 'Approved purchase order is waiting to be sent.',
                'warning', 'purchasing', 'Send the frozen purchase order to the supplier.',
                $tenant->branch->id, PurchaseOrder::class, $purchaseOrder->id
            );
        } elseif (! $replayed && in_array($data['status'], ['sent', 'cancelled'], true)) {
            $alerts->resolveByKey(
                $tenant->organization->id,
                "purchase-order:{$purchaseOrder->id}:approved-not-sent",
                $request->user()->id
            );
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function assertTenant(PurchaseOrder $order, TenantContext $tenant): void
    {
        abort_unless(
            $order->organization_id === $tenant->organization->id
            && $order->branch_id === $tenant->branch->id,
            404
        );
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
