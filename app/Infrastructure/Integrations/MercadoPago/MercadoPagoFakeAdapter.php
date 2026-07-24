<?php

namespace App\Infrastructure\Integrations\MercadoPago;

use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Domain\Integrations\Data\DeliveryResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MercadoPagoFakeAdapter implements PaymentGateway
{
    public function createPayment(array $payment, string $idempotencyKey): DeliveryResult
    {
        return Cache::lock('mercadopago-fake:'.hash('sha256', $idempotencyKey), 30)
            ->block(10, fn () => DB::transaction(function () use ($payment, $idempotencyKey): DeliveryResult {
                $delivery = DB::table('integration_deliveries')->where([
                    'integration' => 'mercadopago_fake', 'operation' => 'create_payment',
                    'idempotency_key' => $idempotencyKey,
                ])->lockForUpdate()->first();
                if ($delivery) {
                    if (! hash_equals(
                        hash('sha256', $delivery->request_payload),
                        hash('sha256', json_encode($payment, JSON_THROW_ON_ERROR))
                    )) {
                        throw ValidationException::withMessages(['Idempotency-Key' => ['Key was reused with another payment.']]);
                    }
                    $response = json_decode($delivery->response_payload ?? '{}', true);

                    return new DeliveryResult(true, $response['payment_id'] ?? null, $response);
                }
                $response = ['payment_id' => 'MP-FAKE-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 10)), 'status' => 'pending'];
                DB::table('integration_deliveries')->insert([
                    'integration' => 'mercadopago_fake', 'operation' => 'create_payment',
                    'idempotency_key' => $idempotencyKey, 'status' => 'pending', 'attempts' => 1,
                    'request_payload' => json_encode($payment, JSON_THROW_ON_ERROR),
                    'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return new DeliveryResult(true, $response['payment_id'], $response);
            }));
    }

    public function parseWebhook(array $payload, array $headers): array
    {
        $externalId = (string) ($payload['id'] ?? '');
        if ($externalId === '') {
            throw ValidationException::withMessages(['id' => ['Webhook id is required.']]);
        }
        $id = DB::table('external_webhooks')->insertOrIgnore([
            'provider' => 'mercadopago', 'external_id' => $externalId,
            'signature' => $headers['x-signature'] ?? null,
            'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['external_id' => $externalId, 'duplicate' => $id === 0];
    }
}
