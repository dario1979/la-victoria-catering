<?php

namespace Tests\Unit;

use App\Domain\Production\UnitConverter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnitConverterTest extends TestCase
{
    public function test_converts_compatible_units_exactly(): void
    {
        $units = new UnitConverter;

        $this->assertSame(1_500_000, $units->toBaseScaled('1.500', 'kg'));
        $this->assertSame(1_500, $units->fromBaseScaledExact(1_500_000, 'kg'));
        $this->assertSame('g', $units->baseUnit('kg'));
        $this->assertSame('ml', $units->baseUnit('l'));
    }

    public function test_rejects_incompatible_dimensions(): void
    {
        $this->expectException(ValidationException::class);

        (new UnitConverter)->assertCompatible('kg', 'l');
    }

    public function test_proportional_calculation_rounds_up(): void
    {
        $units = new UnitConverter;
        $oneGram = $units->toBaseScaled('1.000', 'g');
        $oneUnit = $units->toBaseScaled('1.000', 'unit');
        $threeUnits = $units->toBaseScaled('3.000', 'unit');

        $this->assertSame(334, $units->proportionalCeil($oneGram, $oneUnit, $threeUnits));
    }
}
