<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertManager;
use App\Domain\Finance\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Models\AccountPayablePayment;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Reconciliation;
use App\Support\Decimal;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ReconciliationController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');

        return $table->respond(
            Reconciliation::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id),
            $request,
            ['provider', 'external_reference', 'observations'],
            [
                'id' => 'id', 'provider' => 'provider', 'external_date' => 'external_date',
                'difference_cents' => 'difference_cents', 'status' => 'status', 'created_at' => 'created_at',
            ],
            ['provider' => 'provider', 'status' => 'status', 'internal_type' => 'internal_type'],
            [
                'ID' => 'id', 'Proveedor' => 'provider', 'Tipo interno' => 'internal_type',
                'ID interno' => 'internal_id', 'Referencia externa' => 'external_reference',
                'Interno (centavos)' => 'internal_amount_cents',
                'Externo (centavos)' => 'external_amount_cents',
                'Diferencia (centavos)' => 'difference_cents',
                'Fecha externa' => 'external_date', 'Estado' => 'status',
                'Observaciones' => 'observations',
            ],
            'conciliaciones',
        );
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        IdempotentAction $idempotency,
        AlertManager $alerts,
    ): JsonResponse {
        $access->authorize($tenant, 'reconcile');
        $data = $request->validate([
            'provider' => ['required', Rule::in(['manual', 'mercadopago', 'bank', 'cash'])],
            'internal_type' => ['required', Rule::in(['payment', 'cash_movement', 'payable_payment'])],
            'internal_id' => ['required', 'integer'],
            'external_reference' => ['required', 'string', 'max:255'],
            'external_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'external_date' => ['required', 'date'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        $key = $this->key($request);
        $internalCents = $this->internalAmount(
            $data['internal_type'], (int) $data['internal_id'],
            $tenant->organization->id, $tenant->branch->id
        );
        $externalCents = Decimal::toScaledInt($data['external_amount'], 2);
        $difference = $externalCents - $internalCents;
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.reconciliations.create",
            $key,
            $data,
            function () use ($data, $tenant, $internalCents, $externalCents, $difference): array {
                $reconciliation = Reconciliation::create([
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id, 'provider' => $data['provider'],
                    'internal_type' => $data['internal_type'], 'internal_id' => $data['internal_id'],
                    'external_reference' => $data['external_reference'],
                    'internal_amount_cents' => $internalCents, 'external_amount_cents' => $externalCents,
                    'difference_cents' => $difference, 'external_date' => $data['external_date'],
                    'status' => 'pending', 'observations' => $data['observations'] ?? null,
                ]);

                return [['data' => $reconciliation], 201];
            },
        );
        if (! $replayed && $difference !== 0) {
            $id = data_get($body, 'data.id');
            $alerts->raise(
                $tenant->organization->id, "reconciliation:{$id}:mismatch",
                'ReconciliationMismatchDetected', "difference_cents={$difference}", 'high', 'finance',
                'Review the external evidence and resolve or ignore the mismatch.',
                $tenant->branch->id, Reconciliation::class, (int) $id
            );
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function resolve(
        Request $request,
        Reconciliation $reconciliation,
        TenantContext $tenant,
        FinanceAccess $access,
        IdempotentAction $idempotency,
        AlertManager $alerts,
    ): JsonResponse {
        $access->authorize($tenant, 'reconcile');
        $this->assertTenant($reconciliation, $tenant);
        $data = $request->validate([
            'status' => ['required', Rule::in(['matched', 'mismatched', 'ignored', 'resolved'])],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['status'] === 'matched' && $reconciliation->difference_cents !== 0) {
            throw ValidationException::withMessages(['status' => ['Only a zero-difference reconciliation can be matched.']]);
        }
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.reconciliations.{$reconciliation->id}.resolve",
            $this->key($request),
            $data,
            function () use ($reconciliation, $data, $request): array {
                $reconciliation->update([
                    'status' => $data['status'], 'observations' => $data['observations'] ?? $reconciliation->observations,
                    'reconciled_by' => $request->user()->id, 'reconciled_at' => now()->utc(),
                ]);

                return [['data' => $reconciliation->fresh()], 200];
            },
        );
        if (! $replayed && in_array($data['status'], ['matched', 'ignored', 'resolved'], true)) {
            $alerts->resolveByKey(
                $tenant->organization->id, "reconciliation:{$reconciliation->id}:mismatch",
                $request->user()->id
            );
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function internalAmount(
        string $type,
        int $id,
        int $organizationId,
        int $branchId,
    ): int {
        $model = match ($type) {
            'payment' => Payment::query(),
            'cash_movement' => CashMovement::query(),
            'payable_payment' => AccountPayablePayment::query(),
        };
        $record = $model->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)->findOrFail($id);

        return abs((int) $record->amount_cents);
    }

    private function assertTenant(Reconciliation $reconciliation, TenantContext $tenant): void
    {
        abort_unless(
            $reconciliation->organization_id === $tenant->organization->id
            && $reconciliation->branch_id === $tenant->branch->id,
            404
        );
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
