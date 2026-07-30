<?php

namespace App\Infrastructure\Integrations\Arca;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Domain\Integrations\Data\DeliveryResult;
use RuntimeException;

final class ArcaSandboxAdapter implements FiscalIssuer
{
    public function issue(array $invoice, string $idempotencyKey): DeliveryResult
    {
        throw new RuntimeException('ARCA sandbox credentials and certificates are not configured.');
    }

    public function status(string $externalId): array
    {
        throw new RuntimeException('ARCA sandbox credentials and certificates are not configured.');
    }
}
