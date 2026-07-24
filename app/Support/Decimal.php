<?php

namespace App\Support;

use InvalidArgumentException;

final class Decimal
{
    public static function toScaledInt(string|int $value, int $scale): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }
        $negative = str_starts_with($normalized, '-');
        $unsigned = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException("Value exceeds {$scale} decimal places.");
        }
        $integer = ((int) $whole * (10 ** $scale)) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');

        return $negative ? -$integer : $integer;
    }

    public static function fromScaledInt(int $value, int $scale): string
    {
        $negative = $value < 0 ? '-' : '';
        $absolute = abs($value);
        $factor = 10 ** $scale;

        return $negative.intdiv($absolute, $factor).'.'.str_pad((string) ($absolute % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
