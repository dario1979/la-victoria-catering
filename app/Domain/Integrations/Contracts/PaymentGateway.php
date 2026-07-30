<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\DeliveryResult;

interface PaymentGateway
{
    public function createPayment(array $payment, string $idempotencyKey): DeliveryResult;

    public function paymentStatus(string $externalId): array;

    public function refund(string $externalId, string $amount, string $idempotencyKey): DeliveryResult;

    public function verifyWebhook(array $payload, array $headers): bool;

    public function parseWebhook(array $payload, array $headers): array;
}
