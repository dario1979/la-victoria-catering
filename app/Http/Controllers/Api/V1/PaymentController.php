<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Decimal;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class PaymentController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            Payment::query()->whereHas('order', fn ($query) => $query
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id))
                ->with('order'),
            $request,
            ['external_reference'],
            ['id' => 'id', 'order_id' => 'order_id', 'amount' => 'amount', 'method' => 'method', 'created_at' => 'created_at'],
            ['order_id' => 'order_id', 'method' => 'method'],
            ['ID' => 'id', 'Pedido' => 'order_id', 'Cliente' => 'order.customer_name', 'Importe' => 'amount', 'Medio' => 'method', 'Referencia' => 'external_reference', 'Fecha' => 'created_at'],
            'pagos',
        );
    }

    public function show(Payment $payment, TenantContext $tenant): JsonResponse
    {
        $payment->load('order');
        abort_unless(
            $payment->order->organization_id === $tenant->organization->id
            && $payment->order->branch_id === $tenant->branch->id,
            404
        );

        return response()->json(['data' => $payment]);
    }

    public function store(Request $request, IdempotentAction $idempotency): JsonResponse
    {
        $tenant = app(TenantContext::class);
        $data = $request->validate([
            'order_id' => ['required', Rule::exists('orders', 'id')->where(
                fn ($query) => $query->where('organization_id', $tenant->organization->id)
                    ->where('branch_id', $tenant->branch->id)
            )],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'method' => ['required', 'in:cash,transfer,mercadopago,card'],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $order = Order::query()->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)->findOrFail($data['order_id']);
        Gate::authorize('recordPayment', $order);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        [$body, $status, $replayed] = $idempotency->run("organizations.{$tenant->organization->id}.payments.create", $request->header('Idempotency-Key'), $data, function () use ($data, $tenant): array {
            $order = Order::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->lockForUpdate()->findOrFail($data['order_id']);
            $paid = Decimal::toScaledInt($order->getRawOriginal('paid_total'), 2);
            $amount = Decimal::toScaledInt($data['amount'], 2);
            $total = Decimal::toScaledInt($order->getRawOriginal('total'), 2);
            if ($paid + $amount > $total) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds outstanding balance.']]);
            }
            $paymentId = DB::table('payments')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cash_entries')->insert([
                'payment_id' => $paymentId, 'amount' => $data['amount'], 'reason' => 'payment_received',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $order->update(['paid_total' => Decimal::fromScaledInt($paid + $amount, 2)]);

            return [['data' => ['id' => $paymentId, ...$data]], 201];
        });

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }
}
