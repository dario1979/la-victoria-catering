<?php

namespace App\Domain\Integrations\Data;

final readonly class DeliveryResult
{
    public function __construct(
        public bool $successful,
        public ?string $externalReference,
        public array $payload = [],
        public ?string $error = null,
    ) {}
}
