<?php

namespace App\Jobs;

use App\Domain\Integrations\PaymentIntegrationManager;
use App\Models\PaymentGatewayTransaction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SynchronizePaymentGatewayTransaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public readonly int $transactionId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PaymentIntegrationManager $manager): void
    {
        if (! config('services.mercadopago.enabled')) {
            return;
        }

        $transaction = PaymentGatewayTransaction::query()->find($this->transactionId);
        if (! $transaction || ! $transaction->external_id || ! in_array(
            $transaction->internal_status,
            ['pending', 'processing', 'retrying'],
            true,
        )) {
            return;
        }

        $manager->synchronize($transaction);
    }

    public function failed(?Throwable $exception): void
    {
        $transaction = PaymentGatewayTransaction::query()->find($this->transactionId);
        if (! $transaction) {
            return;
        }

        $transaction->update([
            'internal_status' => 'manual_review',
            'last_error' => $exception?->getMessage() ?? 'Payment synchronization exhausted its retries.',
        ]);
        DB::table('alerts')->updateOrInsert(
            [
                'organization_id' => $transaction->organization_id,
                'deduplication_key' => "mercadopago:transaction:{$transaction->id}:manual-review",
                'status' => 'open',
            ],
            [
                'branch_id' => $transaction->branch_id,
                'event' => 'Pago de Mercado Pago requiere revisión manual',
                'condition' => "La transacción #{$transaction->id} agotó sus reintentos.",
                'severity' => 'high',
                'recipient' => 'finance',
                'action' => 'Revisar el estado en Mercado Pago y reprocesar la sincronización.',
                'occurrences' => 1,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('audit_logs')->insert([
            'event' => 'integration.mercadopago.dead_lettered',
            'subject_type' => PaymentGatewayTransaction::class,
            'subject_id' => $transaction->id,
            'actor_type' => User::class,
            'actor_id' => null,
            'context' => json_encode(['error' => $transaction->last_error], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
