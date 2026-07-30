<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BakeryIntegrityChecker
{
    private array $checks = [];

    public function run(?int $organizationId = null, ?int $branchId = null): array
    {
        $this->checks = [];

        $this->query('inventory.non_negative', 'Los lotes tienen stock disponible no negativo.', $this->scope(
            DB::table('inventory_lots')->selectRaw('id AS violation_id')
                ->where(fn (Builder $query) => $query->where('quantity', '<', 0)->orWhere('reserved_quantity', '<', 0)),
            'organization_id', 'branch_id', $organizationId, $branchId
        ));
        $this->query('inventory.reservations_within_lot', 'Las reservas no superan la cantidad del lote.', $this->scope(
            DB::table('inventory_lots')->selectRaw('id AS violation_id')
                ->whereColumn('reserved_quantity', '>', 'quantity'),
            'organization_id', 'branch_id', $organizationId, $branchId
        ));
        $this->query('inventory.reservation_totals', 'El total reservado coincide con reservas activas.', $this->scope(
            DB::table('inventory_lots as lot')
                ->leftJoin('stock_reservations as reservation', function ($join): void {
                    $join->on('reservation.inventory_lot_id', '=', 'lot.id')
                        ->where('reservation.status', '=', 'active');
                })
                ->selectRaw('lot.id AS violation_id')
                ->groupBy('lot.id', 'lot.reserved_quantity')
                ->havingRaw('ABS(COALESCE(SUM(reservation.quantity), 0) - lot.reserved_quantity) > 0.0005'),
            'lot.organization_id', 'lot.branch_id', $organizationId, $branchId
        ));
        $this->query('inventory.movement_balance', 'La suma de movimientos coincide con la cantidad del lote.', $this->scope(
            DB::table('inventory_lots as lot')
                ->leftJoin('stock_movements as movement', 'movement.inventory_lot_id', '=', 'lot.id')
                ->selectRaw('lot.id AS violation_id')
                ->groupBy('lot.id', 'lot.quantity')
                ->havingRaw('ABS(COALESCE(SUM(movement.quantity), 0) - lot.quantity) > 0.0005'),
            'lot.organization_id', 'lot.branch_id', $organizationId, $branchId
        ));
        $this->query('inventory.tenant_alignment', 'Lotes y movimientos respetan organización, sucursal y referencias.', $this->scope(
            DB::table('stock_movements as movement')
                ->join('inventory_lots as lot', 'lot.id', '=', 'movement.inventory_lot_id')
                ->join('locations as location', 'location.id', '=', 'movement.location_id')
                ->selectRaw('movement.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('movement.organization_id', '!=', 'lot.organization_id')
                    ->orWhereColumn('movement.branch_id', '!=', 'lot.branch_id')
                    ->orWhereColumn('movement.product_id', '!=', 'lot.product_id')
                    ->orWhereColumn('movement.location_id', '!=', 'lot.location_id')
                    ->orWhereColumn('location.organization_id', '!=', 'lot.organization_id')
                    ->orWhereColumn('location.branch_id', '!=', 'lot.branch_id')),
            'movement.organization_id', 'movement.branch_id', $organizationId, $branchId
        ));

        $this->query('orders.totals', 'El total del pedido coincide con sus renglones.', $this->scope(
            DB::table('orders as orders')
                ->leftJoin('order_items as item', 'item.order_id', '=', 'orders.id')
                ->selectRaw('orders.id AS violation_id')
                ->groupBy('orders.id', 'orders.total')
                ->havingRaw('ABS(COALESCE(SUM(item.quantity * item.unit_price), 0) - orders.total) > 0.005'),
            'orders.organization_id', 'orders.branch_id', $organizationId, $branchId
        ));
        $this->query('orders.transitions', 'Las transiciones aceptadas pertenecen a la máquina de estados.', $this->scope(
            DB::table('order_transitions as transition')
                ->join('orders as orders', 'orders.id', '=', 'transition.order_id')
                ->selectRaw('transition.id AS violation_id')
                ->where('transition.accepted', true)
                ->whereNot(function (Builder $query): void {
                    foreach ([
                        ['draft', 'confirmed'], ['draft', 'cancelled'],
                        ['confirmed', 'in_production'], ['confirmed', 'cancelled'],
                        ['in_production', 'ready'], ['in_production', 'cancelled'],
                        ['ready', 'delivered'], ['ready', 'cancelled'],
                    ] as [$from, $to]) {
                        $query->orWhere(fn (Builder $pair) => $pair
                            ->where('transition.from_status', $from)->where('transition.to_status', $to));
                    }
                }),
            'orders.organization_id', 'orders.branch_id', $organizationId, $branchId
        ));
        $latestTransitions = DB::table('order_transitions')
            ->selectRaw('order_id, MAX(id) AS transition_id')->where('accepted', true)->groupBy('order_id');
        $this->query('orders.current_transition', 'El estado actual coincide con la última transición aceptada.', $this->scope(
            DB::table('orders as orders')
                ->joinSub($latestTransitions, 'latest', 'latest.order_id', '=', 'orders.id')
                ->join('order_transitions as transition', 'transition.id', '=', 'latest.transition_id')
                ->selectRaw('orders.id AS violation_id')
                ->whereColumn('orders.status', '!=', 'transition.to_status'),
            'orders.organization_id', 'orders.branch_id', $organizationId, $branchId
        ));

        $this->query('production.state', 'Las órdenes de producción tienen campos coherentes con su estado.', $this->scope(
            DB::table('production_batches as batch')->selectRaw('batch.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $completed) => $completed->where('batch.status', 'completed')
                        ->where(fn (Builder $missing) => $missing->whereNull('batch.completed_at')
                            ->orWhereNull('batch.actual_yield')->orWhereNull('batch.manufactured_at')))
                    ->orWhere(fn (Builder $progress) => $progress->where('batch.status', 'in_progress')
                        ->whereNull('batch.started_at'))
                    ->orWhereNotIn('batch.status', ['planned', 'in_progress', 'completed', 'cancelled'])),
            'batch.organization_id', 'batch.branch_id', $organizationId, $branchId
        ));
        $this->query('production.output_lot', 'Cada producción completada tiene exactamente un lote elaborado alineado.', $this->scope(
            DB::table('production_batches as batch')
                ->leftJoin('inventory_lots as lot', 'lot.production_batch_id', '=', 'batch.id')
                ->join('recipes as recipe', 'recipe.id', '=', 'batch.recipe_id')
                ->selectRaw('batch.id AS violation_id')
                ->where('batch.status', 'completed')
                ->groupBy('batch.id', 'batch.organization_id', 'batch.branch_id', 'recipe.product_id')
                ->havingRaw('COUNT(lot.id) <> 1 OR MIN(lot.organization_id) <> batch.organization_id OR MIN(lot.branch_id) <> batch.branch_id OR MIN(lot.product_id) <> recipe.product_id'),
            'batch.organization_id', 'batch.branch_id', $organizationId, $branchId
        ));
        $this->query('production.consumptions', 'Los consumos coinciden con movimientos negativos y tenant.', $this->scope(
            DB::table('production_consumptions as consumption')
                ->join('stock_movements as movement', 'movement.id', '=', 'consumption.stock_movement_id')
                ->selectRaw('consumption.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('consumption.organization_id', '!=', 'movement.organization_id')
                    ->orWhereColumn('consumption.branch_id', '!=', 'movement.branch_id')
                    ->orWhereColumn('consumption.inventory_lot_id', '!=', 'movement.inventory_lot_id')
                    ->orWhereColumn('consumption.ingredient_product_id', '!=', 'movement.product_id')
                    ->orWhereRaw('ABS(consumption.quantity + movement.quantity) > 0.0005')
                    ->orWhere('movement.type', '!=', 'production_consumption')),
            'consumption.organization_id', 'consumption.branch_id', $organizationId, $branchId
        ));

        $this->query('finance.order_payments', 'El pagado del pedido coincide con pagos y contramovimientos.', $this->scope(
            DB::table('orders as orders')
                ->leftJoin('payments as payment', 'payment.order_id', '=', 'orders.id')
                ->selectRaw('orders.id AS violation_id')
                ->groupBy('orders.id', 'orders.paid_total')
                ->havingRaw('ABS(COALESCE(SUM(payment.amount_cents), 0) - (orders.paid_total * 100)) > 0.5'),
            'orders.organization_id', 'orders.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.payment_tenant', 'Los pagos respetan pedido, cliente, organización y sucursal.', $this->scope(
            DB::table('payments as payment')
                ->join('orders as orders', 'orders.id', '=', 'payment.order_id')
                ->selectRaw('payment.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('payment.organization_id', '!=', 'orders.organization_id')
                    ->orWhereColumn('payment.branch_id', '!=', 'orders.branch_id')
                    ->orWhereColumn('payment.customer_id', '!=', 'orders.customer_id')),
            'payment.organization_id', 'payment.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.duplicate_open_cash', 'No hay sesiones de caja abiertas duplicadas.', $this->scope(
            DB::table('cash_sessions as session')->selectRaw('MIN(session.id) AS violation_id')
                ->whereIn('session.status', ['open', 'closing'])
                ->groupBy('session.cash_register_id')->havingRaw('COUNT(*) > 1'),
            'session.organization_id', 'session.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.cash_balances', 'Los cierres de caja coinciden con la suma de movimientos.', $this->scope(
            DB::table('cash_sessions as session')
                ->leftJoin('cash_movements as movement', 'movement.cash_session_id', '=', 'session.id')
                ->selectRaw('session.id AS violation_id')
                ->whereIn('session.status', ['closing', 'closed', 'closed_with_difference'])
                ->groupBy('session.id', 'session.expected_balance_cents')
                ->havingRaw('session.expected_balance_cents IS NULL OR COALESCE(SUM(movement.amount_cents), 0) <> session.expected_balance_cents'),
            'session.organization_id', 'session.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.cash_tenant', 'Movimientos, sesiones y cajas comparten tenant.', $this->scope(
            DB::table('cash_movements as movement')
                ->join('cash_sessions as session', 'session.id', '=', 'movement.cash_session_id')
                ->join('cash_registers as register', 'register.id', '=', 'session.cash_register_id')
                ->selectRaw('movement.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('movement.organization_id', '!=', 'session.organization_id')
                    ->orWhereColumn('movement.branch_id', '!=', 'session.branch_id')
                    ->orWhereColumn('register.organization_id', '!=', 'session.organization_id')
                    ->orWhereColumn('register.branch_id', '!=', 'session.branch_id')),
            'movement.organization_id', 'movement.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.customer_ledger', 'El ledger de clientes conserva referencias y tenant coherentes.', $this->scope(
            DB::table('customer_account_entries as entry')
                ->join('customers as customer', 'customer.id', '=', 'entry.customer_id')
                ->leftJoin('orders as orders', 'orders.id', '=', 'entry.order_id')
                ->leftJoin('payments as payment', 'payment.id', '=', 'entry.payment_id')
                ->selectRaw('entry.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('entry.organization_id', '!=', 'customer.organization_id')
                    ->orWhere(fn (Builder $order) => $order->whereNotNull('entry.order_id')
                        ->where(fn (Builder $mismatch) => $mismatch
                            ->whereColumn('entry.organization_id', '!=', 'orders.organization_id')
                            ->orWhereColumn('entry.branch_id', '!=', 'orders.branch_id')
                            ->orWhereColumn('entry.customer_id', '!=', 'orders.customer_id')))
                    ->orWhere(fn (Builder $paymentEntry) => $paymentEntry->whereNotNull('entry.payment_id')
                        ->whereColumn('entry.customer_id', '!=', 'payment.customer_id'))),
            'entry.organization_id', 'entry.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.payables', 'Obligaciones y pagos a proveedores conservan saldos exactos.', $this->scope(
            DB::table('accounts_payable as payable')
                ->leftJoin('accounts_payable_payments as payment', 'payment.account_payable_id', '=', 'payable.id')
                ->selectRaw('payable.id AS violation_id')
                ->groupBy('payable.id', 'payable.total_cents', 'payable.paid_cents', 'payable.status')
                ->havingRaw('COALESCE(SUM(payment.amount_cents), 0) <> payable.paid_cents OR payable.paid_cents < 0 OR payable.paid_cents > payable.total_cents OR (payable.status = \'paid\' AND payable.paid_cents <> payable.total_cents)'),
            'payable.organization_id', 'payable.branch_id', $organizationId, $branchId
        ));
        $this->query('finance.reconciliations', 'Las conciliaciones conservan diferencia y estado coherentes.', $this->scope(
            DB::table('reconciliations as reconciliation')->selectRaw('reconciliation.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereRaw('difference_cents <> external_amount_cents - internal_amount_cents')
                    ->orWhere(fn (Builder $matched) => $matched->where('status', 'matched')
                        ->where('difference_cents', '!=', 0))),
            'reconciliation.organization_id', 'reconciliation.branch_id', $organizationId, $branchId
        ));

        $this->query('procurement.receipts', 'Recepciones y órdenes conservan cantidades aceptadas exactas.', $this->scope(
            DB::table('purchase_order_items as item')
                ->join('purchase_orders as orders', 'orders.id', '=', 'item.purchase_order_id')
                ->leftJoin('purchase_receipt_items as receipt_item', 'receipt_item.purchase_order_item_id', '=', 'item.id')
                ->selectRaw('item.id AS violation_id')
                ->groupBy('item.id', 'item.quantity', 'item.received_quantity')
                ->havingRaw('ABS(COALESCE(SUM(receipt_item.accepted_quantity), 0) - item.received_quantity) > 0.0005 OR item.received_quantity < 0 OR item.received_quantity > item.quantity'),
            'orders.organization_id', 'orders.branch_id', $organizationId, $branchId
        ));
        $this->query('procurement.tenant_alignment', 'Recepciones, órdenes y obligaciones respetan tenant y proveedor.', $this->scope(
            DB::table('accounts_payable as payable')
                ->join('purchase_receipts as receipt', 'receipt.id', '=', 'payable.purchase_receipt_id')
                ->join('purchase_orders as orders', 'orders.id', '=', 'payable.purchase_order_id')
                ->selectRaw('payable.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('payable.organization_id', '!=', 'receipt.organization_id')
                    ->orWhereColumn('payable.branch_id', '!=', 'receipt.branch_id')
                    ->orWhereColumn('payable.organization_id', '!=', 'orders.organization_id')
                    ->orWhereColumn('payable.branch_id', '!=', 'orders.branch_id')
                    ->orWhereColumn('payable.supplier_id', '!=', 'orders.supplier_id')),
            'payable.organization_id', 'payable.branch_id', $organizationId, $branchId
        ));

        $this->query('integrations.financial_bounds', 'Documentos fiscales y transacciones externas conservan totales válidos.', $this->scope(
            DB::table('fiscal_documents as document')->selectRaw('document.id AS violation_id')
                ->whereRaw('document.net_cents + document.tax_cents <> document.total_cents'),
            'document.organization_id', 'document.branch_id', $organizationId, $branchId
        ));
        $this->query('integrations.payment_bounds', 'Reembolsos externos no superan el pago y respetan tenant.', $this->scope(
            DB::table('payment_gateway_transactions as gateway')
                ->join('payments as payment', 'payment.id', '=', 'gateway.payment_id')
                ->selectRaw('gateway.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->where('gateway.amount_cents', '<', 0)
                    ->orWhere('gateway.refunded_cents', '<', 0)
                    ->orWhereColumn('gateway.refunded_cents', '>', 'gateway.amount_cents')
                    ->orWhereColumn('gateway.organization_id', '!=', 'payment.organization_id')
                    ->orWhereColumn('gateway.branch_id', '!=', 'payment.branch_id')),
            'gateway.organization_id', 'gateway.branch_id', $organizationId, $branchId
        ));
        $this->query('notifications.tenant_alignment', 'Alertas y notificaciones no quedan huérfanas ni cruzan tenant.', $this->scope(
            DB::table('notification_deliveries as delivery')
                ->leftJoin('alerts as alert', 'alert.id', '=', 'delivery.alert_id')
                ->leftJoin('organization_user as membership', function ($join): void {
                    $join->on('membership.user_id', '=', 'delivery.user_id')
                        ->on('membership.organization_id', '=', 'delivery.organization_id');
                })
                ->selectRaw('delivery.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->whereNull('membership.user_id')
                    ->orWhere(fn (Builder $alertMismatch) => $alertMismatch->whereNotNull('delivery.alert_id')
                        ->where(fn (Builder $mismatch) => $mismatch
                            ->whereColumn('delivery.organization_id', '!=', 'alert.organization_id')
                            ->orWhereRaw('COALESCE(delivery.branch_id, -1) <> COALESCE(alert.branch_id, -1)')))),
            'delivery.organization_id', 'delivery.branch_id', $organizationId, $branchId
        ));
        $this->query('operations.review_tenant_alignment', 'Fallos operativos y webhooks revisados respetan tenant y actor.', $this->scope(
            DB::table('operational_failures as failure')
                ->leftJoin('branches as branch', 'branch.id', '=', 'failure.branch_id')
                ->leftJoin('organization_user as resolver', function ($join): void {
                    $join->on('resolver.user_id', '=', 'failure.resolved_by')
                        ->on('resolver.organization_id', '=', 'failure.organization_id');
                })
                ->selectRaw('failure.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $branchMismatch) => $branchMismatch
                        ->whereNotNull('failure.branch_id')
                        ->where(fn (Builder $mismatch) => $mismatch
                            ->whereNull('failure.organization_id')
                            ->orWhereColumn('branch.organization_id', '!=', 'failure.organization_id')))
                    ->orWhere(fn (Builder $resolverMismatch) => $resolverMismatch
                        ->whereNotNull('failure.resolved_by')
                        ->whereNull('resolver.user_id'))),
            'failure.organization_id', 'failure.branch_id', $organizationId, $branchId
        ));
        $this->query('operations.webhook_tenant_alignment', 'La revisión de webhooks respeta tenant y actor.', $this->scope(
            DB::table('external_webhooks as webhook')
                ->leftJoin('branches as branch', 'branch.id', '=', 'webhook.branch_id')
                ->leftJoin('organization_user as reviewer', function ($join): void {
                    $join->on('reviewer.user_id', '=', 'webhook.reviewed_by')
                        ->on('reviewer.organization_id', '=', 'webhook.organization_id');
                })
                ->selectRaw('webhook.id AS violation_id')
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $branchMismatch) => $branchMismatch
                        ->whereNotNull('webhook.branch_id')
                        ->where(fn (Builder $mismatch) => $mismatch
                            ->whereNull('webhook.organization_id')
                            ->orWhereColumn('branch.organization_id', '!=', 'webhook.organization_id')))
                    ->orWhere(fn (Builder $reviewerMismatch) => $reviewerMismatch
                        ->whereNotNull('webhook.reviewed_by')
                        ->whereNull('reviewer.user_id'))),
            'webhook.organization_id', 'webhook.branch_id', $organizationId, $branchId
        ));
        $this->query('idempotency.payloads', 'Las claves idempotentes tienen hash y respuesta válidos.', DB::table('idempotency_keys')
            ->selectRaw('id AS violation_id')
            ->where(fn (Builder $query) => $query
                ->whereRaw('LENGTH(request_hash) <> 64')
                ->orWhere('response_status', '<', 100)
                ->orWhere('response_status', '>', 599)
                ->orWhere('scope', '')
                ->orWhere('key', '')));

        $violations = array_sum(array_column($this->checks, 'violations'));

        return [
            'status' => $violations === 0 ? 'pass' : 'fail',
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'scope' => ['organization_id' => $organizationId, 'branch_id' => $branchId],
            'summary' => [
                'checks' => count($this->checks),
                'passed' => count(array_filter($this->checks, fn (array $check) => $check['status'] === 'pass')),
                'failed' => count(array_filter($this->checks, fn (array $check) => $check['status'] === 'fail')),
                'violations' => $violations,
            ],
            'checks' => $this->checks,
        ];
    }

    private function scope(
        Builder $query,
        string $organizationColumn,
        ?string $branchColumn,
        ?int $organizationId,
        ?int $branchId,
    ): Builder {
        if ($organizationId !== null) {
            $query->where($organizationColumn, $organizationId);
        }
        if ($branchId !== null && $branchColumn !== null) {
            $query->where($branchColumn, $branchId);
        }

        return $query;
    }

    private function query(string $id, string $description, Builder $violations): void
    {
        $count = DB::query()->fromSub(clone $violations, 'integrity_violations')->count();
        $sample = DB::query()->fromSub(clone $violations, 'integrity_violations')
            ->limit(5)->pluck('violation_id')->map(fn ($value) => (int) $value)->all();
        $this->checks[] = [
            'id' => $id,
            'description' => $description,
            'status' => $count === 0 ? 'pass' : 'fail',
            'violations' => $count,
            'sample_ids' => $sample,
        ];
    }
}
