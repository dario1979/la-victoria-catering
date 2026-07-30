<?php

namespace App\Domain\Finance;

use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerLedger
{
    public function ensureOrderCharge(Order $order, int $actorId, string $idempotencyKey): ?CustomerAccountEntry
    {
        if ($order->customer_id === null) {
            return null;
        }

        return CustomerAccountEntry::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'organization_id' => $order->organization_id, 'branch_id' => $order->branch_id,
                'customer_id' => $order->customer_id, 'type' => 'charge',
                'amount_cents' => Decimal::toScaledInt($order->getRawOriginal('total'), 2),
                'description' => "Pedido #{$order->id}", 'due_on' => optional($order->required_at)->toDateString(),
                'occurred_at' => now()->utc(), 'performed_by' => $actorId,
                'idempotency_key' => $idempotencyKey.':charge',
            ],
        );
    }

    public function recordPayment(Order $order, Payment $payment, int $actorId, string $idempotencyKey): ?CustomerAccountEntry
    {
        if ($order->customer_id === null) {
            return null;
        }
        $this->ensureOrderCharge($order, $actorId, $idempotencyKey);

        return CustomerAccountEntry::create([
            'organization_id' => $order->organization_id, 'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id, 'type' => 'payment',
            'amount_cents' => -abs($payment->amount_cents),
            'description' => "Pago de pedido #{$order->id}", 'payment_id' => $payment->id,
            'occurred_at' => $payment->occurred_at, 'performed_by' => $actorId,
            'idempotency_key' => $idempotencyKey.':ledger',
        ]);
    }

    public function add(
        Customer $customer,
        string $type,
        string $amount,
        string $description,
        ?string $dueOn,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
    ): CustomerAccountEntry {
        abort_unless($customer->organization_id === $organizationId, 404);
        $cents = Decimal::toScaledInt($amount, 2);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }
        $signed = $type === 'charge' ? $cents : -$cents;

        return CustomerAccountEntry::create([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'customer_id' => $customer->id, 'type' => $type, 'amount_cents' => $signed,
            'description' => $description, 'due_on' => $dueOn,
            'occurred_at' => now()->utc(), 'performed_by' => $actorId,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function reverse(
        CustomerAccountEntry $entry,
        string $description,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
    ): CustomerAccountEntry {
        return DB::transaction(function () use (
            $entry, $description, $organizationId, $branchId, $actorId, $idempotencyKey
        ): CustomerAccountEntry {
            $entry = CustomerAccountEntry::query()->lockForUpdate()->findOrFail($entry->id);
            abort_unless(
                $entry->organization_id === $organizationId && $entry->branch_id === $branchId,
                404
            );
            if (CustomerAccountEntry::query()->where('reversal_of_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['entry' => ['Entry was already reversed.']]);
            }

            return CustomerAccountEntry::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'customer_id' => $entry->customer_id, 'type' => 'reversal',
                'amount_cents' => -$entry->amount_cents, 'description' => $description,
                'reversal_of_id' => $entry->id, 'occurred_at' => now()->utc(),
                'performed_by' => $actorId, 'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function summary(Customer $customer, int $organizationId): array
    {
        abort_unless($customer->organization_id === $organizationId, 404);
        $query = CustomerAccountEntry::query()
            ->where('organization_id', $organizationId)->where('customer_id', $customer->id);
        $balance = (int) (clone $query)->sum('amount_cents');
        $overdue = (int) (clone $query)->where('amount_cents', '>', 0)
            ->whereDate('due_on', '<', today())->sum('amount_cents');

        return [
            'customer' => $customer, 'balance_cents' => $balance,
            'overdue_debt_cents' => max(0, min($balance, $overdue)),
            'status' => $balance <= 0 ? ($balance < 0 ? 'credit' : 'settled') : ($overdue > 0 ? 'overdue' : 'open'),
        ];
    }
}
