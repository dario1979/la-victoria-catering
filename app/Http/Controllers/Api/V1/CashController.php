<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\CashManager;
use App\Domain\Finance\FinanceAccess;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class CashController extends Controller
{
    public function registers(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');

        return $table->respond(
            CashRegister::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->with('users:id,name,email'),
            $request,
            ['name'],
            ['id' => 'id', 'name' => 'name', 'active' => 'active', 'created_at' => 'created_at'],
            ['active' => 'active'],
            ['ID' => 'id', 'Caja' => 'name', 'Activa' => 'active', 'Creada' => 'created_at'],
            'cajas',
        );
    }

    public function storeRegister(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-cash');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'authorized_user_ids' => ['sometimes', 'array', 'min:1'],
            'authorized_user_ids.*' => ['integer'],
        ]);
        $userIds = array_values(array_unique($data['authorized_user_ids'] ?? [$request->user()->id]));
        $assigned = DB::table('organization_user')
            ->join('branch_user', 'branch_user.user_id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $tenant->organization->id)
            ->where('branch_user.branch_id', $tenant->branch->id)
            ->whereIn('organization_user.user_id', $userIds)
            ->pluck('organization_user.user_id')->all();
        if (count($assigned) !== count($userIds)) {
            abort(422, 'Every authorized user must belong to the active organization.');
        }
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.branches.{$tenant->branch->id}.cash-registers.create",
            $this->key($request),
            [...$data, 'authorized_user_ids' => $userIds],
            function () use ($data, $userIds, $tenant, $request): array {
                $register = DB::transaction(function () use ($data, $userIds, $tenant, $request): CashRegister {
                    $register = CashRegister::create([
                        'organization_id' => $tenant->organization->id,
                        'branch_id' => $tenant->branch->id, 'name' => $data['name'],
                        'active' => true, 'created_by' => $request->user()->id,
                    ]);
                    $register->users()->sync($userIds);

                    return $register->load('users:id,name,email');
                });

                return [['data' => $register], 201];
            },
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function open(
        Request $request,
        CashRegister $cashRegister,
        TenantContext $tenant,
        FinanceAccess $access,
        CashManager $cash,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'operate-cash');
        $this->assertRegister($cashRegister, $tenant);
        $data = $request->validate([
            'opening_balance' => ['required', 'decimal:0,2', 'gte:0'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.cash-registers.{$cashRegister->id}.open",
            $key,
            $data,
            fn () => [['data' => $cash->open(
                $cashRegister, $data['opening_balance'], $tenant->organization->id,
                $tenant->branch->id, $request->user()->id, $key, $data['observations'] ?? null
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function sessions(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view');

        return $table->respond(
            CashSession::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)->with('register'),
            $request,
            [
                fn ($query, $search, $operator) => $query->orWhereHas(
                    'register',
                    fn ($register) => $register->where('name', $operator, "%{$search}%")
                ),
            ],
            [
                'id' => 'id', 'status' => 'status', 'opened_at' => 'opened_at',
                'closed_at' => 'closed_at', 'difference_cents' => 'difference_cents',
            ],
            ['status' => 'status', 'cash_register_id' => 'cash_register_id'],
            [
                'ID' => 'id', 'Caja' => 'register.name', 'Estado' => 'status',
                'Inicial (centavos)' => 'opening_balance_cents',
                'Esperado (centavos)' => 'expected_balance_cents',
                'Contado (centavos)' => 'counted_balance_cents',
                'Diferencia (centavos)' => 'difference_cents', 'Apertura UTC' => 'opened_at',
                'Cierre UTC' => 'closed_at', 'Observaciones' => 'observations',
            ],
            'sesiones-caja',
        );
    }

    public function show(
        CashSession $cashSession,
        TenantContext $tenant,
        FinanceAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view');
        $this->assertSession($cashSession, $tenant);

        return response()->json(['data' => $cashSession->load(['register', 'movements'])]);
    }

    public function movement(
        Request $request,
        CashSession $cashSession,
        TenantContext $tenant,
        FinanceAccess $access,
        CashManager $cash,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'operate-cash');
        $this->assertSession($cashSession, $tenant);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.cash-sessions.{$cashSession->id}.movements.create",
            $key,
            $data,
            fn () => [['data' => $cash->addMovement(
                $cashSession, $data['kind'], $data['amount'], $data['reason'],
                $tenant->organization->id, $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function reverse(
        Request $request,
        CashMovement $cashMovement,
        TenantContext $tenant,
        FinanceAccess $access,
        CashManager $cash,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-cash');
        abort_unless(
            $cashMovement->organization_id === $tenant->organization->id
            && $cashMovement->branch_id === $tenant->branch->id,
            404
        );
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.cash-movements.{$cashMovement->id}.reverse",
            $key,
            $data,
            fn () => [['data' => $cash->reverse(
                $cashMovement, $data['reason'], $tenant->organization->id,
                $tenant->branch->id, $request->user()->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function close(
        Request $request,
        CashSession $cashSession,
        TenantContext $tenant,
        FinanceAccess $access,
        CashManager $cash,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'operate-cash');
        $this->assertSession($cashSession, $tenant);
        $data = $request->validate([
            'counted_balance' => ['nullable', 'decimal:0,2', 'gte:0'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.cash-sessions.{$cashSession->id}.close",
            $this->key($request),
            $data,
            fn () => [['data' => $cash->close(
                $cashSession, $data['counted_balance'] ?? null, $data['observations'] ?? null,
                $tenant->organization->id, $tenant->branch->id, $request->user()->id
            )], 200],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function approveDifference(
        Request $request,
        CashSession $cashSession,
        TenantContext $tenant,
        FinanceAccess $access,
        CashManager $cash,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'approve-difference');
        $this->assertSession($cashSession, $tenant);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.cash-sessions.{$cashSession->id}.approve-difference",
            $this->key($request),
            [],
            fn () => [['data' => $cash->approveDifference(
                $cashSession, $tenant->organization->id, $tenant->branch->id, $request->user()->id
            )], 200],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function assertRegister(CashRegister $register, TenantContext $tenant): void
    {
        abort_unless(
            $register->organization_id === $tenant->organization->id
            && $register->branch_id === $tenant->branch->id,
            404
        );
    }

    private function assertSession(CashSession $session, TenantContext $tenant): void
    {
        abort_unless(
            $session->organization_id === $tenant->organization->id
            && $session->branch_id === $tenant->branch->id,
            404
        );
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}
