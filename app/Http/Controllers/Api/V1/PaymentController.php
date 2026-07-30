<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\CashManager;
use App\Domain\Finance\CustomerLedger;
use App\Domain\Finance\FinanceAccess;
use App\Domain\Integrations\PaymentIntegrationManager;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CustomerAccountEntry;
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
    public function index(
        Request $request,
        TenantContext $tenant,
        ServerDataTable $table,
        FinanceAccess $access,
    ): Response {
        $access->authorize($tenant, 'view');

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

    public function show(Payment $payment, TenantContext $tenant, FinanceAccess $access): JsonResponse
    {
        $access->authorize($tenant, 'view');
        $payment->load('order');
        abort_unless(
            $payment->order->organization_id === $tenant->organization->id
            && $payment->order->branch_id === $tenant->branch->id,
            404
        );

        return response()->json(['data' => $payment]);
    }

    public function store(
        Request $request,
        IdempotentAction $idempotency,
        FinanceAccess $access,
        CashManager $cash,
        CustomerLedger $ledger,
        PaymentIntegrationManager $paymentIntegration,
    ): JsonResponse {
        $tenant = app(TenantContext::class);
        $access->authorize($tenant, 'operate-cash');
        $data = $request->validate([
            'order_id' => ['required', Rule::exists('orders', 'id')->where(
                fn ($query) => $query->where('organization_id', $tenant->organization->id)
                    ->where('branch_id', $tenant->branch->id)
            )],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'method' => ['required', 'in:cash,transfer,mercadopago,card'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'cash_session_id' => [
                'required_if:method,cash', 'nullable',
                'prohibited_unless:method,cash',
                Rule::exists('cash_sessions', 'id')->where(
                    fn ($query) => $query->where('organization_id', $tenant->organization->id)
                        ->where('branch_id', $tenant->branch->id)->where('status', 'open')
                ),
            ],
        ]);
        if ($data['method'] === 'mercadopago') {
            abort_unless(config('services.mercadopago.enabled'), 409, 'Mercado Pago integration is disabled.');
        }
        $order = Order::query()->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)->findOrFail($data['order_id']);
        Gate::authorize('recordPayment', $order);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        $key = (string) $request->header('Idempotency-Key');
        [$body, $status, $replayed] = $idempotency->run("organizations.{$tenant->organization->id}.payments.create", $key, $data, function () use ($data, $tenant, $request, $cash, $ledger, $paymentIntegration, $key): array {
            $order = Order::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->lockForUpdate()->findOrFail($data['order_id']);
            $paid = Decimal::toScaledInt($order->getRawOriginal('paid_total'), 2);
            $amount = Decimal::toScaledInt($data['amount'], 2);
            $total = Decimal::toScaledInt($order->getRawOriginal('total'), 2);
            if ($paid + $amount > $total) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds outstanding balance.']]);
            }
            $payment = Payment::create([
                'organization_id' => $tenant->organization->id, 'branch_id' => $tenant->branch->id,
                'order_id' => $order->id, 'customer_id' => $order->customer_id,
                'cash_session_id' => $data['cash_session_id'] ?? null,
                'amount' => $data['amount'], 'amount_cents' => $amount, 'method' => $data['method'],
                'external_reference' => $data['external_reference'] ?? null, 'status' => 'recorded',
                'recorded_by' => $request->user()->id, 'occurred_at' => now()->utc(),
            ]);
            if ($data['method'] === 'cash') {
                DB::table('cash_entries')->insert([
                    'payment_id' => $payment->id, 'amount' => $data['amount'], 'reason' => 'payment_received',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $cash->addMovement(
                    CashSession::findOrFail($data['cash_session_id']),
                    'income', $data['amount'], "Cobro pedido #{$order->id}",
                    $tenant->organization->id, $tenant->branch->id, $request->user()->id,
                    $key.':cash', Payment::class, $payment->id
                );
            }
            $order->update(['paid_total' => Decimal::fromScaledInt($paid + $amount, 2)]);
            $ledger->recordPayment($order, $payment, $request->user()->id, $key);
            if ($data['method'] === 'mercadopago') {
                $paymentIntegration->create($payment, $key.':mercadopago');
            }

            return [['data' => $payment->fresh()->toArray()], 201];
        });

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function reverse(
        Request $request,
        Payment $payment,
        IdempotentAction $idempotency,
        FinanceAccess $access,
        CashManager $cash,
        CustomerLedger $ledger,
    ): JsonResponse {
        $tenant = app(TenantContext::class);
        $access->authorize($tenant, 'manage-ledger');
        abort_unless(
            $payment->organization_id === $tenant->organization->id
            && $payment->branch_id === $tenant->branch->id,
            404
        );
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        $key = (string) $request->header('Idempotency-Key');
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.payments.{$payment->id}.reverse",
            $key,
            $data,
            function () use ($payment, $tenant, $request, $key, $data, $cash, $ledger): array {
                $reversal = DB::transaction(function () use (
                    $payment, $tenant, $request, $key, $data, $cash, $ledger
                ): Payment {
                    $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                    if ($payment->status === 'reversed' || Payment::query()->where('reversal_of_id', $payment->id)->exists()) {
                        throw ValidationException::withMessages(['payment' => ['Payment was already reversed.']]);
                    }
                    $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
                    $paid = Decimal::toScaledInt($order->getRawOriginal('paid_total'), 2);
                    $reversal = Payment::create([
                        'organization_id' => $tenant->organization->id, 'branch_id' => $tenant->branch->id,
                        'order_id' => $order->id, 'customer_id' => $payment->customer_id,
                        'cash_session_id' => $payment->cash_session_id,
                        'amount' => Decimal::fromScaledInt(-$payment->amount_cents, 2),
                        'amount_cents' => -$payment->amount_cents, 'method' => $payment->method,
                        'external_reference' => $payment->external_reference, 'status' => 'reversal',
                        'reversal_of_id' => $payment->id, 'recorded_by' => $request->user()->id,
                        'occurred_at' => now()->utc(),
                    ]);
                    DB::table('payments')->where('id', $payment->id)->update(['status' => 'reversed', 'updated_at' => now()]);
                    $order->update(['paid_total' => Decimal::fromScaledInt($paid - $payment->amount_cents, 2)]);
                    $entry = CustomerAccountEntry::query()->where('payment_id', $payment->id)->first();
                    if ($entry) {
                        $ledger->reverse(
                            $entry, $data['reason'], $tenant->organization->id,
                            $tenant->branch->id, $request->user()->id, $key.':ledger'
                        );
                    }
                    $movement = CashMovement::query()
                        ->where('reference_type', Payment::class)->where('reference_id', $payment->id)->first();
                    if ($movement) {
                        $cash->reverse(
                            $movement, $data['reason'], $tenant->organization->id,
                            $tenant->branch->id, $request->user()->id, $key.':cash'
                        );
                    }

                    return $reversal;
                });

                return [['data' => $reversal], 201];
            },
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }
}
