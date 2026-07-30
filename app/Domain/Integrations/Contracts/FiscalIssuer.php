<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\DeliveryResult;

interface FiscalIssuer
{
    public function issue(array $invoice, string $idempotencyKey): DeliveryResult;

    public function status(string $externalId): array;
}
