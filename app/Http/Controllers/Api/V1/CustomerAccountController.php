<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\CustomerLedger;
use App\Domain\Finance\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class CustomerAccountController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');
        $entries = CustomerAccountEntry::query()
            ->selectRaw('COALESCE(SUM(amount_cents), 0)')
            ->whereColumn('customer_id', 'customers.id')
            ->where('organization_id', $tenant->organization->id);
        $overdue = CustomerAccountEntry::query()
            ->selectRaw('COALESCE(SUM(amount_cents), 0)')
            ->whereColumn('customer_id', 'customers.id')
            ->where('organization_id', $tenant->organization->id)
            ->where('amount_cents', '>', 0)->whereDate('due_on', '<', today());

        return $table->respond(
            Customer::query()->where('organization_id', $tenant->organization->id)
                ->select('customers.*')->selectSub($entries, 'balance_cents')
                ->selectSub($overdue, 'overdue_debt_cents'),
            $request,
            ['name', 'tax_id', 'email'],
            ['id' => 'id', 'name' => 'name', 'balance_cents' => 'balance_cents', 'created_at' => 'created_at'],
            ['active' => 'active'],
            [
                'ID' => 'id', 'Cliente' => 'name', 'CUIT' => 'tax_id',
                'Saldo (centavos)' => 'balance_cents', 'Vencido (centavos)' => 'overdue_debt_cents',
                'Límite' => 'credit_limit', 'Activo' => 'active',
            ],
            'cuentas-corrientes',
        );
    }

    public function entries(
        Request $request,
        Customer $customer,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');
        abort_unless($customer->organization_id === $tenant->organization->id, 404);

        return $table->respond(
            CustomerAccountEntry::query()->where('organization_id', $tenant->organization->id)
                ->where('customer_id', $customer->id)->where('branch_id', $tenant->branch->id),
            $request,
            ['description'],
            ['id' => 'id', 'type' => 'type', 'amount_cents' => 'amount_cents', 'due_on' => 'due_on', 'occurred_at' => 'occurred_at'],
            ['type' => 'type'],
            [
                'ID' => 'id', 'Tipo' => 'type', 'Importe (centavos)' => 'amount_cents',
                'Descripción' => 'description', 'Vencimiento' => 'due_on',
                'Fecha UTC' => 'occurred_at', 'Pedido' => 'order_id', 'Pago' => 'payment_id',
            ],
            "cuenta-corriente-{$customer->id}",
        );
    }

    public function summary(
        Customer $customer,
        TenantContext $tenant,
        FinanceAccess $access,
        CustomerLedger $ledger,
    ): JsonResponse {
        $access->authorize($tenant, 'view');

        return response()->json(['data' => $ledger->summary($customer, $tenant->organization->id)]);
    }

    public function store(
        Request $request,
        Customer $customer,
        TenantContext $tenant,
        FinanceAccess $access,
        CustomerLedger $ledger,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-ledger');
        abort_unless($customer->organization_id === $tenant->organization->id, 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(['charge', 'payment', 'advance', 'credit_note'])],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'due_on' => ['nullable', 'date'],
        ]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.customers.{$customer->id}.ledger.create",
            $key,
            $data,
            fn () => [['data' => $ledger->add(
                $customer, $data['type'], $data['amount'], $data['description'], $data['due_on'] ?? null,
                $tenant->organization->id, $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function reverse(
        Request $request,
        CustomerAccountEntry $customerAccountEntry,
        TenantContext $tenant,
        FinanceAccess $access,
        CustomerLedger $ledger,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-ledger');
        abort_unless(
            $customerAccountEntry->organization_id === $tenant->organization->id
            && $customerAccountEntry->branch_id === $tenant->branch->id,
            404
        );
        $data = $request->validate(['description' => ['required', 'string', 'max:255']]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.customer-account-entries.{$customerAccountEntry->id}.reverse",
            $key,
            $data,
            fn () => [['data' => $ledger->reverse(
                $customerAccountEntry, $data['description'], $tenant->organization->id,
                $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
