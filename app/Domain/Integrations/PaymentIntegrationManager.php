<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\PaymentGatewayTransaction;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PaymentIntegrationManager
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function create(Payment $payment, string $idempotencyKey): PaymentGatewayTransaction
    {
        return DB::transaction(function () use ($payment, $idempotencyKey): PaymentGatewayTransaction {
            $transaction = PaymentGatewayTransaction::query()->firstOrCreate(
                ['organization_id' => $payment->organization_id, 'idempotency_key' => $idempotencyKey],
                [
                    'branch_id' => $payment->branch_id, 'payment_id' => $payment->id,
                    'provider' => 'mercadopago', 'internal_status' => 'processing',
                    'amount_cents' => $payment->amount_cents,
                ],
            );
            if ($transaction->external_id) {
                return $transaction;
            }
            try {
                $result = $this->gateway->createPayment([
                    'transaction_amount' => $payment->amount,
                    'external_reference' => "payment:{$payment->id}",
                    'description' => "Cobro La Victoria #{$payment->id}",
                ], $idempotencyKey);
            } catch (Throwable $exception) {
                $transaction->update([
                    'internal_status' => 'retrying',
                    'last_error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
            $transaction->update([
                'external_id' => $result->externalReference,
                'external_status' => $result->payload['status'] ?? null,
                'internal_status' => $result->successful ? 'pending' : 'manual_review',
                'last_synced_at' => now()->utc(),
            ]);

            return $transaction->fresh();
        });
    }

    public function synchronize(PaymentGatewayTransaction $transaction): PaymentGatewayTransaction
    {
        try {
            $status = $this->gateway->paymentStatus($transaction->external_id);
        } catch (Throwable $exception) {
            $transaction->update([
                'internal_status' => 'retrying',
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
        $external = (string) ($status['status'] ?? 'unknown');
        $internal = match ($external) {
            'approved' => 'approved',
            'rejected', 'cancelled' => 'rejected',
            'refunded' => 'refunded',
            'pending', 'in_process' => 'pending',
            default => 'manual_review',
        };
        $transaction->update([
            'external_status' => $external, 'internal_status' => $internal,
            'last_synced_at' => now()->utc(), 'last_error' => null,
        ]);

        return $transaction->fresh();
    }
}
