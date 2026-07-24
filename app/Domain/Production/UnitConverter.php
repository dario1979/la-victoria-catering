<?php

namespace App\Domain\Production;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

final class UnitConverter
{
    private const UNITS = [
        'g' => ['dimension' => 'mass', 'factor' => 1, 'base' => 'g'],
        'kg' => ['dimension' => 'mass', 'factor' => 1000, 'base' => 'g'],
        'ml' => ['dimension' => 'volume', 'factor' => 1, 'base' => 'ml'],
        'l' => ['dimension' => 'volume', 'factor' => 1000, 'base' => 'ml'],
        'unit' => ['dimension' => 'count', 'factor' => 1, 'base' => 'unit'],
    ];

    public function assertCompatible(string $from, string $to): void
    {
        if (! isset(self::UNITS[$from], self::UNITS[$to])
            || self::UNITS[$from]['dimension'] !== self::UNITS[$to]['dimension']) {
            throw ValidationException::withMessages([
                'unit' => ["No conversion exists between {$from} and {$to}."],
            ]);
        }
    }

    public function baseUnit(string $unit): string
    {
        $this->assertKnown($unit);

        return self::UNITS[$unit]['base'];
    }

    public function toBaseScaled(string|int $quantity, string $unit): int
    {
        $this->assertKnown($unit);

        return Decimal::toScaledInt($quantity, 3) * self::UNITS[$unit]['factor'];
    }

    public function fromBaseScaledCeil(int $baseQuantity, string $unit): int
    {
        $this->assertKnown($unit);
        $factor = self::UNITS[$unit]['factor'];

        return intdiv($baseQuantity + $factor - 1, $factor);
    }

    public function fromBaseScaledExact(int $baseQuantity, string $unit): int
    {
        $this->assertKnown($unit);
        $factor = self::UNITS[$unit]['factor'];
        if ($baseQuantity % $factor !== 0) {
            throw ValidationException::withMessages([
                'unit' => ["Quantity cannot be represented in {$unit} with three decimals."],
            ]);
        }

        return intdiv($baseQuantity, $factor);
    }

    public function proportionalCeil(int $ingredientBase, int $plannedBase, int $yieldBase): int
    {
        if ($ingredientBase < 0 || $plannedBase <= 0 || $yieldBase <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Recipe quantities and yield must be positive.']]);
        }
        if ($ingredientBase !== 0 && $plannedBase > intdiv(PHP_INT_MAX, $ingredientBase)) {
            throw ValidationException::withMessages(['quantity' => ['Recipe calculation exceeds supported range.']]);
        }

        return intdiv(($ingredientBase * $plannedBase) + $yieldBase - 1, $yieldBase);
    }

    private function assertKnown(string $unit): void
    {
        if (! isset(self::UNITS[$unit])) {
            throw ValidationException::withMessages(['unit' => ["Unknown unit {$unit}."]]);
        }
    }
}
