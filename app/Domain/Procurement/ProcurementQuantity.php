<?php

namespace App\Domain\Procurement;

use App\Domain\Production\UnitConverter;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

final class ProcurementQuantity
{
    public function __construct(private readonly UnitConverter $units) {}

    public function toBase(string|int $quantity, string $purchaseUnit, string $baseUnit, string|int $factor): string
    {
        $this->units->assertCompatible($purchaseUnit, $baseUnit);
        $quantityScaled = Decimal::toScaledInt($quantity, 3);
        $factorScaled = Decimal::toScaledInt($factor, 6);
        if ($quantityScaled < 0 || $factorScaled <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity cannot be negative and conversion factor must be positive.']]);
        }
        if ($quantityScaled !== 0 && $factorScaled > intdiv(PHP_INT_MAX, $quantityScaled)) {
            throw ValidationException::withMessages(['quantity' => ['Converted quantity exceeds the supported range.']]);
        }
        $scaledProduct = $quantityScaled * $factorScaled;
        if ($scaledProduct % 1_000_000 !== 0) {
            throw ValidationException::withMessages(['quantity' => ['Converted quantity exceeds three decimal places in the product unit.']]);
        }

        return Decimal::fromScaledInt(intdiv($scaledProduct, 1_000_000), 3);
    }
}
