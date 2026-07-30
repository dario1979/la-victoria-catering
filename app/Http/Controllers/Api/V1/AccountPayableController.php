<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\AccountsPayableManager;
use App\Domain\Finance\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Models\AccountPayable;
use App\Models\AccountPayablePayment;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class AccountPayableController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');

        return $table->respond(
            AccountPayable::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->with(['supplier', 'payments']),
            $request,
            [
                'document',
                fn ($query, $search, $operator) => $query->orWhereHas(
                    'supplier',
                    fn ($supplier) => $supplier->where('trade_name', $operator, "%{$search}%")
                ),
            ],
            [
                'id' => 'id', 'document' => 'document', 'due_on' => 'due_on',
                'total_cents' => 'total_cents', 'paid_cents' => 'paid_cents',
                'status' => 'status', 'operational_status' => 'status',
            ],
            [
                'status' => function ($query, $value): void {
                    if ($value === 'overdue') {
                        $query->where('status', '!=', 'paid')->whereDate('due_on', '<', today());
                    } else {
                        $query->where('status', $value);
                    }
                },
                'supplier_id' => 'supplier_id', 'currency' => 'currency',
            ],
            [
                'ID' => 'id', 'Proveedor' => 'supplier.trade_name', 'Documento' => 'document',
                'Fecha' => 'document_date', 'Vencimiento' => 'due_on', 'Moneda' => 'currency',
                'Total (centavos)' => 'total_cents', 'Pagado (centavos)' => 'paid_cents',
                'Saldo (centavos)' => fn ($row) => $row->total_cents - $row->paid_cents,
                'Estado' => 'operational_status',
            ],
            'cuentas-por-pagar',
        );
    }

    public function show(
        AccountPayable $accountPayable,
        TenantContext $tenant,
        FinanceAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view');
        $this->assertTenant($accountPayable, $tenant);

        return response()->json(['data' => $accountPayable->load(['supplier', 'payments'])]);
    }

    public function pay(
        Request $request,
        AccountPayable $accountPayable,
        TenantContext $tenant,
        FinanceAccess $access,
        AccountsPayableManager $manager,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-payables');
        $this->assertTenant($accountPayable, $tenant);
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'transfer', 'mercadopago', 'card', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.accounts-payable.{$accountPayable->id}.payments.create",
            $key,
            $data,
            fn () => [['data' => $manager->pay(
                $accountPayable, $data['amount'], $data['method'], $data['external_reference'] ?? null,
                $tenant->organization->id, $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function reverse(
        Request $request,
        AccountPayablePayment $accountPayablePayment,
        TenantContext $tenant,
        FinanceAccess $access,
        AccountsPayableManager $manager,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-payables');
        abort_unless(
            $accountPayablePayment->organization_id === $tenant->organization->id
            && $accountPayablePayment->branch_id === $tenant->branch->id,
            404
        );
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.accounts-payable-payments.{$accountPayablePayment->id}.reverse",
            $key,
            [],
            fn () => [['data' => $manager->reverse(
                $accountPayablePayment, $tenant->organization->id,
                $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function assertTenant(AccountPayable $payable, TenantContext $tenant): void
    {
        abort_unless(
            $payable->organization_id === $tenant->organization->id
            && $payable->branch_id === $tenant->branch->id,
            404
        );
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
