<?php

namespace App\Support\Pilot;

use App\Jobs\QueueAlertNotifications;
use App\Models\PilotScenario;
use App\Models\User;
use App\Support\BakeryIntegrityChecker;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PilotRehearsalRunner
{
    public function __construct(private readonly BakeryIntegrityChecker $integrity) {}

    public function run(PilotScenario $scenario): array
    {
        if ($scenario->status === 'passed' && is_array($scenario->result)) {
            return array_replace($scenario->result, ['replayed' => true]);
        }

        $started = hrtime(true);
        try {
            $result = DB::transaction(fn (): array => $this->execute($scenario));
        } catch (Throwable $exception) {
            $scenario->update([
                'status' => 'failed',
                'result' => [
                    'status' => 'failed',
                    'scenario' => $scenario->identifier,
                    'error' => $exception->getMessage(),
                ],
                'rehearsed_at' => now(),
            ]);
            throw $exception;
        }
        $result['duration_ms'] = round((hrtime(true) - $started) / 1_000_000, 2);
        $scenario->update(['status' => 'passed', 'result' => $result, 'rehearsed_at' => now()]);

        return $result;
    }

    private function execute(PilotScenario $scenario): array
    {
        $admin = User::findOrFail($scenario->participants['admin_user_id']);
        $sales = User::findOrFail($scenario->participants['sales_user_id']);
        $fixtures = $scenario->fixtures;
        $prefix = "pilot:{$scenario->identifier}";
        $api = new PilotApiClient($admin, $scenario->organization_id, $scenario->branch_id);

        $orderPayload = [
            'customer_id' => $fixtures['customer_id'],
            'required_at' => now()->addDay()->toISOString(),
            'items' => [[
                'product_id' => $fixtures['finished_product_id'],
                'quantity' => '5.000',
                'unit_price' => '100.00',
            ]],
        ];
        $order = $api->post('/api/v1/orders', $orderPayload, "{$prefix}:order", 201);
        $orderReplay = $api->post('/api/v1/orders', $orderPayload, "{$prefix}:order", 201);
        $orderId = $order['body']['data']['id'];
        if ($orderReplay['body']['data']['id'] !== $orderId
            || ($orderReplay['headers']['idempotency-replayed'][0] ?? '') !== 'true') {
            throw new RuntimeException('La repetición idempotente del pedido no fue reconocida.');
        }
        $api->post("/api/v1/orders/{$orderId}/transitions", ['status' => 'confirmed'], "{$prefix}:order:confirm");
        $orderDetail = $api->get("/api/v1/orders/{$orderId}")['body']['data'];
        if (($orderDetail['reservations'][0]['inventory_lot_id'] ?? null) !== $fixtures['fefo_lot_id']) {
            throw new RuntimeException('FEFO no seleccionó el lote vigente con vencimiento más próximo.');
        }

        $shortage = $api->post('/api/v1/orders', [
            'customer_id' => $fixtures['customer_id'],
            'items' => [[
                'product_id' => $fixtures['finished_product_id'],
                'quantity' => '99.000',
                'unit_price' => '100.00',
            ]],
        ], "{$prefix}:shortage", 201)['body']['data'];
        $api->post(
            "/api/v1/orders/{$shortage['id']}/transitions",
            ['status' => 'confirmed'],
            "{$prefix}:shortage:confirm",
            422,
        );

        $purchasePayload = [
            'supplier_id' => $fixtures['supplier_id'],
            'ordered_at' => today()->toDateString(),
            'expected_at' => today()->addDays(2)->toDateString(),
            'currency' => 'ARS',
            'payment_terms' => 'Cuenta corriente a 15 días',
            'items' => [[
                'supplier_product_id' => $fixtures['supplier_product_id'],
                'quantity' => '2.000',
                'unit_price' => '100.00',
            ]],
        ];
        $purchase = $api->post('/api/v1/purchase-orders', $purchasePayload, "{$prefix}:purchase", 201)['body']['data'];
        $purchaseId = $purchase['id'];
        $purchaseItemId = $purchase['items'][0]['id'];
        $api->post("/api/v1/purchase-orders/{$purchaseId}/transitions", ['status' => 'approved'], "{$prefix}:purchase:approve");
        $api->post("/api/v1/purchase-orders/{$purchaseId}/transitions", ['status' => 'sent'], "{$prefix}:purchase:send");
        $receiptBase = [
            'received_at' => now()->subMinute()->toISOString(),
            'items' => [[
                'purchase_order_item_id' => $purchaseItemId,
                'location_id' => $fixtures['location_id'],
                'lot_code' => strtoupper("PILOT-ING-{$scenario->id}"),
                'expires_at' => today()->addDays(90)->toDateString(),
                'actual_unit_cost' => '100.00',
            ]],
        ];
        $partialPayload = $receiptBase;
        $partialPayload['notes'] = 'Recepción parcial del ensayo';
        $partialPayload['items'][0] += [
            'received_quantity' => '0.800',
            'accepted_quantity' => '0.600',
            'rejected_quantity' => '0.200',
            'discrepancy_type' => 'damaged',
            'discrepancy_reason' => 'Envase ficticio dañado',
        ];
        $partial = $api->post(
            "/api/v1/purchase-orders/{$purchaseId}/receipts",
            $partialPayload,
            "{$prefix}:receipt:partial",
            201,
        )['body']['data'];
        $finalPayload = $receiptBase;
        $finalPayload['notes'] = 'Recepción final del ensayo';
        $finalPayload['items'][0] += [
            'received_quantity' => '1.400',
            'accepted_quantity' => '1.400',
            'rejected_quantity' => '0.000',
        ];
        $final = $api->post(
            "/api/v1/purchase-orders/{$purchaseId}/receipts",
            $finalPayload,
            "{$prefix}:receipt:final",
            201,
        )['body']['data'];
        if ($api->get("/api/v1/purchase-orders/{$purchaseId}")['body']['data']['status'] !== 'received') {
            throw new RuntimeException('La recepción final no cerró la orden de compra.');
        }

        $production = $api->post('/api/v1/production-orders', [
            'order_id' => $orderId,
            'recipe_id' => $fixtures['recipe_id'],
            'planned_quantity' => '10.000',
            'unit' => 'unit',
        ], "{$prefix}:production", 201)['body']['data'];
        $productionId = $production['id'];
        $api->post("/api/v1/production-orders/{$productionId}/start", [], "{$prefix}:production:start");
        $completed = $api->post("/api/v1/production-orders/{$productionId}/complete", [
            'actual_yield' => '9.000',
            'unit' => 'unit',
            'waste_quantity' => '1.000',
            'destination_location_id' => $fixtures['location_id'],
            'manufactured_at' => now()->toISOString(),
            'expires_at' => today()->addDays(4)->toDateString(),
            'observations' => 'Producción ficticia del ensayo',
        ], "{$prefix}:production:complete")['body']['data'];

        $cash = $api->post("/api/v1/cash-registers/{$fixtures['cash_register_id']}/open", [
            'opening_balance' => '100.00',
            'observations' => 'Apertura técnica del ensayo',
        ], "{$prefix}:cash:open", 201)['body']['data'];
        $payment = $api->post('/api/v1/payments', [
            'order_id' => $orderId,
            'amount' => '200.00',
            'method' => 'cash',
            'cash_session_id' => $cash['id'],
        ], "{$prefix}:payment", 201)['body']['data'];
        $closed = $api->post("/api/v1/cash-sessions/{$cash['id']}/close", [
            'counted_balance' => '300.00',
            'observations' => 'Arqueo técnico sin diferencia',
        ], "{$prefix}:cash:close")['body']['data'];
        if ($closed['difference_cents'] !== 0) {
            throw new RuntimeException('El arqueo técnico produjo una diferencia inesperada.');
        }
        $account = $api->get("/api/v1/customer-accounts/{$fixtures['customer_id']}")['body']['data'];
        $payables = $api->get('/api/v1/accounts-payable?per_page=10')['body'];
        if ($payables['meta']['total'] < 2) {
            throw new RuntimeException('Las recepciones no generaron las cuentas por pagar esperadas.');
        }
        $reconciliation = $api->post('/api/v1/reconciliations', [
            'provider' => 'cash',
            'internal_type' => 'payment',
            'internal_id' => $payment['id'],
            'external_reference' => strtoupper("PILOT-REC-{$scenario->id}"),
            'external_amount' => '200.00',
            'external_date' => today()->toDateString(),
            'observations' => 'Conciliación ficticia del ensayo',
        ], "{$prefix}:reconciliation", 201)['body']['data'];
        $api->post("/api/v1/reconciliations/{$reconciliation['id']}/status", [
            'status' => 'matched',
            'observations' => 'Evidencia técnica coincidente',
        ], "{$prefix}:reconciliation:match");

        $alerts = $api->get('/api/v1/alerts?filter_status=open&per_page=100')['body']['data'];
        if ($alerts === []) {
            throw new RuntimeException('El ensayo no generó ninguna alerta verificable.');
        }
        $alert = $alerts[0];
        $api->post("/api/v1/alerts/{$alert['id']}/acknowledge", []);
        app(QueueAlertNotifications::class)->handle();
        $notifications = $api->get('/api/v1/notifications?per_page=100')['body'];
        if ($notifications['meta']['total'] < 1) {
            throw new RuntimeException('La alerta no materializó una notificación interna.');
        }

        $restricted = new PilotApiClient($sales, $scenario->organization_id, $scenario->branch_id);
        $restricted->post('/api/v1/purchase-orders', $purchasePayload, "{$prefix}:forbidden", 403);

        $integrity = $this->integrity->run($scenario->organization_id, $scenario->branch_id);
        if ($integrity['status'] !== 'pass') {
            throw new RuntimeException('El integrity check del escenario no pasó.');
        }

        return [
            'status' => 'passed',
            'scenario' => $scenario->identifier,
            'environment' => app()->environment(),
            'replayed' => false,
            'ids' => [
                'order' => $orderId,
                'shortage_order' => $shortage['id'],
                'purchase_order' => $purchaseId,
                'partial_receipt' => $partial['id'],
                'final_receipt' => $final['id'],
                'production' => $productionId,
                'produced_lot' => $completed['produced_lot']['id'],
                'cash_session' => $cash['id'],
                'payment' => $payment['id'],
                'reconciliation' => $reconciliation['id'],
                'alert' => $alert['id'],
            ],
            'assertions' => [
                'fefo_lot_id' => $fixtures['fefo_lot_id'],
                'customer_balance_cents' => $account['balance_cents'],
                'accounts_payable' => $payables['meta']['total'],
                'notifications' => $notifications['meta']['total'],
                'integrity_checks' => $integrity['summary']['checks'],
                'integrity_violations' => $integrity['summary']['violations'],
                'negative_permission_status' => 403,
            ],
            'requests' => [...$api->calls(), ...$restricted->calls()],
        ];
    }
}
