<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Support;

use Buckmerce\Plaid\Exception\PaymentException;

/**
 * Converts WooCommerce order totals into the exact decimal-string format Plaid
 * Transfer expects ("12.34"). Totals that cannot be represented exactly are
 * rejected instead of being rounded through floating point.
 *
 * Sums and differences (refund limits) are computed in integer cents from the
 * validated two-decimal strings, never in binary floating point.
 */
final class Money
{
    public const SUPPORTED_CURRENCY = 'USD';
    /** Upper bound far above any ACH limit; keeps cent arithmetic far from integer overflow. */
    private const MAX_CENTS = 100000000000000;

    /** @throws PaymentException */
    public static function transfer_amount(string $order_total): string
    {
        $normal = Decimal::normalise($order_total);
        if ('' === $normal || 0 === Decimal::compare($normal, '0')) {
            throw new PaymentException('The order total is not a positive decimal amount.');
        }
        if (Decimal::scale($normal) > 2) {
            throw new PaymentException('The order total has more than two decimal places and cannot be charged exactly.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normal, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    public static function same_amount(string $left, string $right): bool
    {
        return 0 === Decimal::compare($left, $right);
    }

    /**
     * Exact integer cents of a non-negative amount with at most two decimals.
     *
     * @throws PaymentException
     */
    public static function to_cents(string $amount): int
    {
        $normal = Decimal::normalise($amount);
        if ('' === $normal || Decimal::scale($normal) > 2) {
            throw new PaymentException('The amount is not a decimal with at most two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normal, 2), 2, '');
        if (strlen($whole) > 12) {
            throw new PaymentException('The amount is out of range.');
        }
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new PaymentException('The amount is out of range.');
        }
        return $cents;
    }

    /** Two-decimal string of a non-negative number of cents. */
    public static function from_cents(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Negative money amounts are not supported.');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<string> $amounts
     * @throws PaymentException
     */
    public static function sum(array $amounts): string
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::to_cents($amount);
        }
        return self::from_cents($total);
    }

    /**
     * $left − $right, never below zero (a negative remainder means nothing is left).
     *
     * @throws PaymentException
     */
    public static function remaining(string $left, string $right): string
    {
        return self::from_cents(max(0, self::to_cents($left) - self::to_cents($right)));
    }
}
