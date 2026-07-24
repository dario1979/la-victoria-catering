<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\OrderWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Decimal;
use App\Support\IdempotentAction;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OrderController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $orders = Order::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('search'), fn ($query) => $query->where('customer_name', 'like', '%'.trim($request->query('search')).'%'))
            ->with('items')->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($orders);
    }

    public function store(Request $request, IdempotentAction $idempotency): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $tenant = app(TenantContext::class);
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('organization_id', $tenant->organization->id)],
            'customer_name' => ['required_without:customer_id', 'string', 'max:255'],
            'required_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $tenant->organization->id)],
            'items.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'gte:0'],
        ]);
        $data['organization_id'] = $tenant->organization->id;
        $data['branch_id'] = $tenant->branch->id;
        if (isset($data['customer_id'])) {
            $data['customer_name'] = Customer::findOrFail($data['customer_id'])->name;
        }
        [$body, $status, $replayed] = $idempotency->run("organizations.{$tenant->organization->id}.orders.create", $this->key($request), $data, function () use ($data): array {
            $order = DB::transaction(function () use ($data): Order {
                $items = $data['items'];
                unset($data['items']);
                $totalCents = collect($items)->sum(function (array $item): int {
                    $quantity = Decimal::toScaledInt($item['quantity'], 3);
                    $unitPrice = Decimal::toScaledInt($item['unit_price'], 2);

                    return intdiv(($quantity * $unitPrice) + 500, 1000);
                });
                $data['total'] = Decimal::fromScaledInt($totalCents, 2);
                $order = Order::create($data);
                $order->items()->createMany($items);

                return $order->load('items');
            });

            return [['data' => $order->toArray()], 201];
        });

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function transition(Request $request, Order $order, OrderWorkflow $workflow, IdempotentAction $idempotency): JsonResponse
    {
        Gate::authorize('transition', $order);
        $tenant = app(TenantContext::class);
        $data = $request->validate(['status' => ['required', 'string']]);
        [$body, $status, $replayed] = $idempotency->run("organizations.{$tenant->organization->id}.orders.{$order->id}.transition", $this->key($request), $data, function () use ($workflow, $order, $data, $request): array {
            return [['data' => $workflow->transition($order, $data['status'], $request->user()->id)->toArray()], 200];
        });

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(Order $order, TenantContext $tenant): JsonResponse
    {
        abort_unless(
            $order->organization_id === $tenant->organization->id
            && $order->branch_id === $tenant->branch->id,
            404
        );

        return response()->json(['data' => $order->load([
            'customer', 'items', 'transitions', 'reservations',
        ])]);
    }

    public function allowedTransitions(Order $order, OrderWorkflow $workflow, TenantContext $tenant): JsonResponse
    {
        abort_unless(
            $order->organization_id === $tenant->organization->id
            && $order->branch_id === $tenant->branch->id,
            404
        );

        return response()->json(['data' => $workflow->allowedTransitions($order)]);
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return $request->header('Idempotency-Key');
    }
}
