<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\DeliverOrder;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\IdempotentAction;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeliveryController extends Controller
{
    public function store(
        Request $request,
        Order $order,
        DeliverOrder $delivery,
        IdempotentAction $keys,
        TenantContext $tenant,
    ): JsonResponse {
        abort_unless($tenant->can('owner', 'admin', 'sales'), 403);
        abort_unless($order->organization_id === $tenant->organization->id && $order->branch_id === $tenant->branch->id, 404);
        $data = $request->validate([
            'method' => ['required', 'in:pickup,delivery'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        [$body, $status, $replayed] = $keys->run(
            "organizations.{$tenant->organization->id}.orders.{$order->id}.deliver",
            $request->header('Idempotency-Key'), $data,
            fn () => [['data' => $delivery->execute(
                $order, $tenant, $request->user()->id, $data['method'], $data['notes'] ?? null
            )->toArray()], 200]
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }
}
