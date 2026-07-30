<?php

namespace App\Jobs;

use App\Models\PaymentGatewayTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynchronizePendingPaymentGatewayTransactions implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! config('services.mercadopago.enabled')) {
            return;
        }

        PaymentGatewayTransaction::query()
            ->whereIn('internal_status', ['pending', 'processing', 'retrying'])
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->eachById(fn (PaymentGatewayTransaction $transaction) => SynchronizePaymentGatewayTransaction::dispatch(
                $transaction->id,
            ));
    }
}
