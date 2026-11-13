<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/** Converts between user-facing currency units and exact integer minor units. */
final class Money
{
    public static function toMinorUnits(int|float|string $amount): int
    {
        $value = (string) $amount;

        if (! preg_match('/^(-?)(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Money amounts must have at most two decimal places.');
        }

        $whole = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '', 2, '0');
        $minorUnits = ($whole * 100) + $fraction;

        return $matches[1] === '-' ? -$minorUnits : $minorUnits;
    }

    public static function fromMinorUnits(int $amount): int|float
    {
        return $amount % 100 === 0 ? intdiv($amount, 100) : $amount / 100;
    }
}
