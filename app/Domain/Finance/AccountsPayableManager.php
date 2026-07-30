<?php

namespace App\Domain\Finance;

use App\Models\AccountPayable;
use App\Models\AccountPayablePayment;
use App\Models\PurchaseReceipt;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountsPayableManager
{
    public function fromReceipt(PurchaseReceipt $receipt): AccountPayable
    {
        $receipt->loadMissing(['order.items', 'items']);
        $items = $receipt->order->items->keyBy('id');
        $totalCents = $receipt->items->sum(function ($receiptItem) use ($items): int {
            $orderItem = $items->get($receiptItem->purchase_order_item_id);
            $quantity = Decimal::toScaledInt($receiptItem->getRawOriginal('accepted_quantity'), 3);
            $unitPrice = Decimal::toScaledInt(
                $receiptItem->getRawOriginal('actual_unit_cost') ?? $orderItem->getRawOriginal('unit_price'),
                2
            );

            return intdiv(($quantity * $unitPrice) + 500, 1000);
        });

        return AccountPayable::query()->firstOrCreate(
            ['purchase_receipt_id' => $receipt->id],
            [
                'organization_id' => $receipt->organization_id, 'branch_id' => $receipt->branch_id,
                'supplier_id' => $receipt->order->supplier_id, 'purchase_order_id' => $receipt->purchase_order_id,
                'document' => $receipt->number, 'document_date' => $receipt->received_at->toDateString(),
                'due_on' => $receipt->received_at->addDays(15)->toDateString(),
                'currency' => $receipt->order->currency, 'total_cents' => $totalCents,
                'paid_cents' => 0, 'status' => 'open',
            ],
        );
    }

    public function pay(
        AccountPayable $payable,
        string $amount,
        string $method,
        ?string $reference,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
    ): AccountPayablePayment {
        $cents = Decimal::toScaledInt($amount, 2);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }

        return DB::transaction(function () use (
            $payable, $cents, $method, $reference, $organizationId, $branchId, $actorId, $idempotencyKey
        ): AccountPayablePayment {
            $payable = AccountPayable::query()->lockForUpdate()->findOrFail($payable->id);
            abort_unless(
                $payable->organization_id === $organizationId && $payable->branch_id === $branchId,
                404
            );
            if ($payable->paid_cents + $cents > $payable->total_cents) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds payable balance.']]);
            }
            $payment = AccountPayablePayment::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'account_payable_id' => $payable->id, 'amount_cents' => $cents,
                'method' => $method, 'external_reference' => $reference,
                'performed_by' => $actorId, 'occurred_at' => now()->utc(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $paid = $payable->paid_cents + $cents;
            $payable->update(['paid_cents' => $paid, 'status' => $paid === $payable->total_cents ? 'paid' : 'partial']);

            return $payment;
        });
    }

    public function reverse(
        AccountPayablePayment $payment,
        int $organizationId,
        int $branchId,
        int $actorId,
        string $idempotencyKey,
    ): AccountPayablePayment {
        return DB::transaction(function () use (
            $payment, $organizationId, $branchId, $actorId, $idempotencyKey
        ): AccountPayablePayment {
            $payment = AccountPayablePayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless(
                $payment->organization_id === $organizationId && $payment->branch_id === $branchId,
                404
            );
            if (AccountPayablePayment::query()->where('reversal_of_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => ['Payment was already reversed.']]);
            }
            $payable = AccountPayable::query()->lockForUpdate()->findOrFail($payment->account_payable_id);
            $reversal = AccountPayablePayment::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'account_payable_id' => $payable->id, 'amount_cents' => -$payment->amount_cents,
                'method' => $payment->method, 'external_reference' => $payment->external_reference,
                'reversal_of_id' => $payment->id, 'performed_by' => $actorId,
                'occurred_at' => now()->utc(), 'idempotency_key' => $idempotencyKey,
            ]);
            $paid = $payable->paid_cents - $payment->amount_cents;
            $payable->update(['paid_cents' => $paid, 'status' => $paid === 0 ? 'open' : 'partial']);

            return $reversal;
        });
    }
}
