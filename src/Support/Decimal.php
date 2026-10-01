<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Support;

/** Decimal-string arithmetic helpers that never convert money to binary floating point. */
final class Decimal
{
    public static function equal(string $left, string $right): bool
    {
        $normal_left = self::normalise($left);
        $normal_right = self::normalise($right);
        return '' !== $normal_left && '' !== $normal_right && $normal_left === $normal_right;
    }

    /** Returns the canonical form of an unsigned decimal string, or '' when it is not one. */
    public static function normalise(string $amount): string
    {
        $amount = trim($amount);
        if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $amount)) {
            return '';
        }
        $parts = explode('.', $amount, 2);
        $whole = ltrim($parts[0], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return '' === $fraction ? $whole : $whole . '.' . $fraction;
    }

    /** Compare two unsigned decimal strings; null when either operand is not a decimal string. */
    public static function compare(string $left, string $right): ?int
    {
        $left = self::normalise($left);
        $right = self::normalise($right);
        if ('' === $left || '' === $right) {
            return null;
        }
        [$left_whole, $left_fraction] = array_pad(explode('.', $left, 2), 2, '');
        [$right_whole, $right_fraction] = array_pad(explode('.', $right, 2), 2, '');
        if (strlen($left_whole) !== strlen($right_whole)) {
            return strlen($left_whole) <=> strlen($right_whole);
        }
        $whole_comparison = strcmp($left_whole, $right_whole);
        if (0 !== $whole_comparison) {
            return $whole_comparison <=> 0;
        }
        $scale = max(strlen($left_fraction), strlen($right_fraction));
        $fraction_comparison = strcmp(str_pad($left_fraction, $scale, '0'), str_pad($right_fraction, $scale, '0'));
        return $fraction_comparison <=> 0;
    }

    /** Number of significant fraction digits of a normalised decimal string. */
    public static function scale(string $amount): int
    {
        $normal = self::normalise($amount);
        $dot = strpos($normal, '.');
        return false === $dot ? 0 : strlen($normal) - $dot - 1;
    }
}
