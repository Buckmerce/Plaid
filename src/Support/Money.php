<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Support;

use PayBridge\Plaid\Exception\PaymentException;

/**
 * Converts WooCommerce order totals into the exact decimal-string format Plaid
 * Transfer expects ("12.34"). Totals that cannot be represented exactly are
 * rejected instead of being rounded through floating point.
 */
final class Money
{
    public const SUPPORTED_CURRENCY = 'USD';

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
}
