<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertManager;
use App\Domain\Procurement\ProcurementAccess;
use App\Domain\Procurement\ReceivePurchaseOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReceivePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseReceiptController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view-orders');

        return $table->respond(
            PurchaseReceipt::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->with(['order.supplier', 'items']),
            $request,
            [
                'number',
                fn ($query, $search, $operator) => $query
                    ->orWhereHas('order', fn ($order) => $order->where('number', $operator, "%{$search}%")),
            ],
            ['id' => 'id', 'number' => 'number', 'received_at' => 'received_at', 'created_at' => 'created_at'],
            ['purchase_order_id' => 'purchase_order_id'],
            [
                'ID' => 'id', 'Número' => 'number',
                'Orden de compra' => fn ($row) => $row->order->number,
                'Proveedor' => fn ($row) => $row->order->supplier->trade_name,
                'Recepción' => 'received_at', 'Notas' => 'notes',
            ],
            'recepciones-compra',
        );
    }

    public function store(
        ReceivePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        TenantContext $tenant,
        ProcurementAccess $access,
        ReceivePurchaseOrder $receiver,
        IdempotentAction $idempotency,
        AlertManager $alerts,
    ): JsonResponse {
        $access->authorize($tenant, 'receive-orders');
        abort_unless(
            $purchaseOrder->organization_id === $tenant->organization->id
            && $purchaseOrder->branch_id === $tenant->branch->id,
            404
        );
        $data = $request->validated();
        try {
            [$body, $status, $replayed] = $idempotency->run(
                "organizations.{$tenant->organization->id}.purchase-orders.{$purchaseOrder->id}.receipts.create",
                $this->key($request),
                $data,
                fn () => [['data' => $receiver->execute(
                    $purchaseOrder, $data, $tenant->organization->id,
                    $tenant->branch->id, $request->user()->id
                )->toArray()], 201],
            );
        } catch (ValidationException $exception) {
            $alerts->raise(
                $tenant->organization->id,
                "purchase-order:{$purchaseOrder->id}:receipt-failed",
                'PurchaseReceiptFailed',
                'A receipt was rejected by stock or purchase invariants.',
                'high',
                'purchasing',
                'Review the receipt data and retry with the same Idempotency-Key.',
                $tenant->branch->id,
                PurchaseOrder::class,
                $purchaseOrder->id,
            );
            throw $exception;
        }
        if (! $replayed) {
            $alerts->resolveByKey(
                $tenant->organization->id,
                "purchase-order:{$purchaseOrder->id}:receipt-failed",
                $request->user()->id
            );
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(
        PurchaseReceipt $purchaseReceipt,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view-orders');
        abort_unless(
            $purchaseReceipt->organization_id === $tenant->organization->id
            && $purchaseReceipt->branch_id === $tenant->branch->id,
            404
        );

        return response()->json(['data' => $purchaseReceipt->load([
            'order.supplier', 'items.product', 'items.location', 'items.inventoryLot', 'items.stockMovement',
        ])]);
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
