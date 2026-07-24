<?php

namespace App\Infrastructure\Integrations\MercadoPago;

use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Domain\Integrations\Data\DeliveryResult;
use RuntimeException;

final class MercadoPagoSandboxAdapter implements PaymentGateway
{
    public function createPayment(array $payment, string $idempotencyKey): DeliveryResult
    {
        throw new RuntimeException('Mercado Pago sandbox token is not configured.');
    }

    public function parseWebhook(array $payload, array $headers): array
    {
        throw new RuntimeException('Mercado Pago webhook signature secret is not configured.');
    }
}
