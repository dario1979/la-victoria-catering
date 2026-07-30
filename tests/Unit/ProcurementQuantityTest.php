<?php

namespace Tests\Unit;

use App\Domain\Procurement\ProcurementQuantity;
use App\Domain\Production\UnitConverter;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcurementQuantityTest extends TestCase
{
    #[DataProvider('exactConversions')]
    public function test_converts_purchase_quantities_exactly(
        string $quantity,
        string $purchaseUnit,
        string $baseUnit,
        string $factor,
        string $expected,
    ): void {
        $converter = new ProcurementQuantity(new UnitConverter);

        self::assertSame($expected, $converter->toBase($quantity, $purchaseUnit, $baseUnit, $factor));
    }

    public static function exactConversions(): array
    {
        return [
            'kilograms to grams' => ['1.250', 'kg', 'g', '1000.000000', '1250.000'],
            'supplier pack factor' => ['2.000', 'unit', 'unit', '12.000000', '24.000'],
            'litres to millilitres' => ['0.500', 'l', 'ml', '1000.000000', '500.000'],
        ];
    }

    public function test_rejects_incompatible_or_unrepresentable_quantities(): void
    {
        $converter = new ProcurementQuantity(new UnitConverter);

        $this->expectException(ValidationException::class);
        $converter->toBase('0.001', 'kg', 'g', '0.000001');
    }
}
