<?php

namespace App\Domain\Finance;

use App\Domain\Alerts\AlertManager;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CashManager
{
    public function __construct(private readonly AlertManager $alerts) {}

    public function open(
        CashRegister $register,
        string $openingBalance,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
        ?string $observations = null,
    ): CashSession {
        $cents = Decimal::toScaledInt($openingBalance, 2);
        if ($cents < 0) {
            throw ValidationException::withMessages(['opening_balance' => ['Opening balance cannot be negative.']]);
        }

        return DB::transaction(function () use (
            $register, $cents, $organizationId, $branchId, $actorId, $idempotencyKey, $observations
        ): CashSession {
            $register = CashRegister::query()->lockForUpdate()->findOrFail($register->id);
            abort_unless(
                $register->organization_id === $organizationId && $register->branch_id === $branchId,
                404
            );
            abort_unless($register->active, 422, 'Cash register is inactive.');
            $this->assertAuthorized($register, $actorId);
            if ($register->sessions()->whereIn('status', ['open', 'closing'])->exists()) {
                throw ValidationException::withMessages(['cash_register_id' => ['Cash register already has an active session.']]);
            }
            $session = CashSession::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_register_id' => $register->id, 'status' => 'open',
                'opening_balance_cents' => $cents, 'observations' => $observations,
                'opened_by' => $actorId, 'opened_at' => now()->utc(),
            ]);
            CashMovement::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_session_id' => $session->id, 'kind' => 'opening',
                'amount_cents' => $cents, 'reason' => 'Saldo inicial',
                'performed_by' => $actorId, 'occurred_at' => now()->utc(),
                'idempotency_key' => $idempotencyKey.':opening',
            ]);
            $this->audit('cash.session.opened', CashSession::class, $session->id, $actorId, [
                'opening_balance_cents' => $cents,
            ]);

            return $session->fresh(['register', 'movements']);
        });
    }

    public function addMovement(
        CashSession $session,
        string $kind,
        string $amount,
        string $reason,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): CashMovement {
        $cents = Decimal::toScaledInt($amount, 2);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }
        $signed = $kind === 'expense' ? -$cents : $cents;

        return DB::transaction(function () use (
            $session, $kind, $signed, $reason, $organizationId, $branchId, $actorId,
            $idempotencyKey, $referenceType, $referenceId
        ): CashMovement {
            $session = CashSession::query()->with('register')->lockForUpdate()->findOrFail($session->id);
            $this->assertSession($session, $organizationId, $branchId, $actorId);
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['status' => ['Cash session is not open.']]);
            }
            $movement = CashMovement::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_session_id' => $session->id, 'kind' => $kind,
                'amount_cents' => $signed, 'reason' => $reason,
                'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'performed_by' => $actorId, 'occurred_at' => now()->utc(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit('cash.movement.created', CashMovement::class, $movement->id, $actorId, [
                'cash_session_id' => $session->id, 'kind' => $kind, 'amount_cents' => $signed,
            ]);

            return $movement;
        });
    }

    public function reverse(
        CashMovement $movement,
        string $reason,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
    ): CashMovement {
        return DB::transaction(function () use (
            $movement, $reason, $organizationId, $branchId, $actorId, $idempotencyKey
        ): CashMovement {
            $movement = CashMovement::query()->with('session.register')->lockForUpdate()->findOrFail($movement->id);
            abort_unless(
                $movement->organization_id === $organizationId && $movement->branch_id === $branchId,
                404
            );
            $this->assertSession($movement->session, $organizationId, $branchId, $actorId);
            if ($movement->session->status !== 'open' || $movement->kind === 'opening') {
                throw ValidationException::withMessages(['movement' => ['Only non-opening movements in an open session can be reversed.']]);
            }
            if (CashMovement::query()->where('reversal_of_id', $movement->id)->exists()) {
                throw ValidationException::withMessages(['movement' => ['Movement was already reversed.']]);
            }

            return CashMovement::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_session_id' => $movement->cash_session_id, 'kind' => 'reversal',
                'amount_cents' => -$movement->amount_cents, 'reason' => $reason,
                'reference_type' => CashMovement::class, 'reference_id' => $movement->id,
                'reversal_of_id' => $movement->id, 'performed_by' => $actorId,
                'occurred_at' => now()->utc(), 'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function close(
        CashSession $session,
        ?string $countedBalance,
        ?string $observations,
        int $organizationId,
        int $branchId,
        int $actorId,
    ): CashSession {
        $counted = $countedBalance === null ? null : Decimal::toScaledInt($countedBalance, 2);
        if ($counted !== null && $counted < 0) {
            throw ValidationException::withMessages(['counted_balance' => ['Counted balance cannot be negative.']]);
        }
        $result = DB::transaction(function () use (
            $session, $counted, $observations, $organizationId, $branchId, $actorId
        ): CashSession {
            $session = CashSession::query()->with('register')->lockForUpdate()->findOrFail($session->id);
            $this->assertSession($session, $organizationId, $branchId, $actorId);
            if (in_array($session->status, ['closed', 'closed_with_difference'], true)) {
                return $session;
            }
            if (! in_array($session->status, ['open', 'closing'], true)) {
                throw ValidationException::withMessages(['status' => ['Cash session cannot be closed.']]);
            }
            $expected = (int) CashMovement::query()->where('cash_session_id', $session->id)->sum('amount_cents');
            if ($counted === null) {
                $session->update([
                    'status' => 'closing', 'expected_balance_cents' => $expected,
                    'observations' => $observations,
                ]);

                return $session->fresh(['register', 'movements']);
            }
            $difference = $counted - $expected;
            $session->update([
                'status' => $difference === 0 ? 'closed' : 'closed_with_difference',
                'expected_balance_cents' => $expected, 'counted_balance_cents' => $counted,
                'difference_cents' => $difference, 'observations' => $observations,
                'closed_by' => $actorId, 'closed_at' => now()->utc(),
            ]);
            $this->audit('cash.session.closed', CashSession::class, $session->id, $actorId, [
                'expected_balance_cents' => $expected, 'counted_balance_cents' => $counted,
                'difference_cents' => $difference,
            ]);

            return $session->fresh(['register', 'movements']);
        });

        if ($result->difference_cents !== null && $result->difference_cents !== 0) {
            $this->alerts->raise(
                $organizationId, "cash-session:{$result->id}:difference", 'CashDifferenceDetected',
                "difference_cents={$result->difference_cents}", 'high', 'finance',
                'Review the cash count, record observations and approve the difference.',
                $branchId, CashSession::class, $result->id
            );
        }

        return $result;
    }

    public function approveDifference(
        CashSession $session,
        int $organizationId,
        int $branchId,
        int $actorId,
    ): CashSession {
        $session = DB::transaction(function () use ($session, $organizationId, $branchId, $actorId): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_unless(
                $session->organization_id === $organizationId && $session->branch_id === $branchId,
                404
            );
            if ($session->status !== 'closed_with_difference') {
                throw ValidationException::withMessages(['status' => ['Session has no closed difference to approve.']]);
            }
            if ($session->difference_approved_at !== null) {
                return $session;
            }
            $session->update([
                'difference_approved_by' => $actorId, 'difference_approved_at' => now()->utc(),
            ]);
            $this->audit('cash.difference.approved', CashSession::class, $session->id, $actorId, [
                'difference_cents' => $session->difference_cents,
            ]);

            return $session->fresh(['register', 'movements']);
        });
        $this->alerts->resolveByKey($organizationId, "cash-session:{$session->id}:difference", $actorId);

        return $session;
    }

    private function assertAuthorized(CashRegister $register, int $actorId): void
    {
        abort_unless($register->users()->whereKey($actorId)->exists(), 403, 'User is not authorized for this cash register.');
    }

    private function assertSession(
        CashSession $session,
        int $organizationId,
        int $branchId,
        int $actorId,
    ): void {
        abort_unless(
            $session->organization_id === $organizationId && $session->branch_id === $branchId,
            404
        );
        $this->assertAuthorized($session->register, $actorId);
    }

    private function audit(string $event, string $subjectType, int $subjectId, int $actorId, array $context): void
    {
        DB::table('audit_logs')->insert([
            'event' => $event, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'actor_type' => User::class, 'actor_id' => $actorId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
