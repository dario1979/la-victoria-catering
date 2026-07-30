<?php

namespace App\Infrastructure\Integrations\MercadoPago;

use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Domain\Integrations\Data\DeliveryResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MercadoPagoSandboxAdapter implements PaymentGateway
{
    public function createPayment(array $payment, string $idempotencyKey): DeliveryResult
    {
        $response = $this->request()->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->post('/v1/payments', $payment)->throw()->json();

        return new DeliveryResult(true, (string) $response['id'], $response);
    }

    public function paymentStatus(string $externalId): array
    {
        return $this->request()->get("/v1/payments/{$externalId}")->throw()->json();
    }

    public function refund(string $externalId, string $amount, string $idempotencyKey): DeliveryResult
    {
        $response = $this->request()->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->post("/v1/payments/{$externalId}/refunds", ['amount' => $amount])->throw()->json();

        return new DeliveryResult(true, (string) $response['id'], $response);
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        throw new RuntimeException('Mercado Pago webhook verification requires configured sandbox credentials.');
    }

    public function parseWebhook(array $payload, array $headers): array
    {
        throw new RuntimeException('Mercado Pago webhook signature secret is not configured.');
    }

    private function request()
    {
        $endpoint = config('services.mercadopago.endpoint');
        $token = config('services.mercadopago.access_token');
        if (! $endpoint || ! $token) {
            throw new RuntimeException('Mercado Pago sandbox token is not configured.');
        }

        return Http::baseUrl($endpoint)->withToken($token)->acceptJson()->timeout(10)->retry(2, 250);
    }
}
