<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\DeliveryResult;

interface PaymentGateway
{
    public function createPayment(array $payment, string $idempotencyKey): DeliveryResult;

    public function parseWebhook(array $payload, array $headers): array;
}
