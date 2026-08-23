<?php

namespace justinholtweb\waver\helpers;

/**
 * Money arithmetic for the two-decimal world Wave insists on.
 *
 * Wave's own words: "All amounts should be entered as positive values with up to 2 decimal
 * places." Commerce keeps money in PHP floats. Rounding each line independently and then summing
 * them does not reliably reproduce the order total, so every amount that goes over the wire is
 * rounded here, once, and the residual is dealt with explicitly rather than left to drift.
 */
abstract class Money
{
    /**
     * Wave's precision, in decimal places.
     */
    public const SCALE = 2;

    /**
     * Round to Wave's precision, half away from zero — the same tie-breaking Wave documents for
     * its own Decimal fields.
     */
    public static function round(float $amount): float
    {
        return round($amount, self::SCALE, PHP_ROUND_HALF_UP);
    }

    /**
     * The string form Wave should receive. `json_encode()` on a float can emit `1.0E-5` or
     * `115.50000000000001`; a Decimal scalar takes a string happily and unambiguously.
     */
    public static function format(float $amount): string
    {
        return number_format(self::round($amount), self::SCALE, '.', '');
    }

    /**
     * Whether two amounts are equal once rounded. Never compare money with `==`.
     */
    public static function equals(float $a, float $b): bool
    {
        return abs(self::round($a) - self::round($b)) < 0.005;
    }

    public static function isZero(float $amount): bool
    {
        return abs(self::round($amount)) < 0.005;
    }

    /**
     * Sum a list of floats at Wave's precision.
     *
     * @param float[] $amounts
     */
    public static function sum(array $amounts): float
    {
        $total = 0.0;

        foreach ($amounts as $amount) {
            $total += self::round((float)$amount);
        }

        return self::round($total);
    }
}
