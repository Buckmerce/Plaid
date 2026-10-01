<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Support\Money;

/**
 * Identifies the WooCommerce refund object behind a process_refund() call.
 *
 * WooCommerce saves its refund object and then calls the gateway with only
 * (order ID, amount, reason). The Plaid refund must be bound to that exact object (its ID
 * is part of the idempotency key), so the object is captured from the
 * woocommerce_create_refund action fired just before it is saved in the same request.
 * If the action was not observed (a caller used the gateway directly), the newest unpaid
 * refund of the order with the same amount, created in the last minutes, is used.
 */
final class WooRefundContext
{
    private const FALLBACK_MAX_AGE_SECONDS = 600;

    /** @var array<int, \WC_Order_Refund> Order ID → refund object created in this request. */
    private static array $captured = array();

    public static function register(): void
    {
        add_action('woocommerce_create_refund', array(self::class, 'capture'), 10, 1);
    }

    /** @param mixed $refund */
    public static function capture($refund): void
    {
        if ($refund instanceof \WC_Order_Refund && $refund->get_parent_id() > 0) {
            self::$captured[$refund->get_parent_id()] = $refund;
        }
    }

    public static function for_order(\WC_Order $order, string $amount): ?\WC_Order_Refund
    {
        try {
            $cents = Money::to_cents(Money::transfer_amount($amount));
        } catch (PaymentException) {
            return null;
        }
        $captured = self::$captured[$order->get_id()] ?? null;
        if ($captured instanceof \WC_Order_Refund && $captured->get_id() > 0 && self::amount_cents($captured) === $cents) {
            unset(self::$captured[$order->get_id()]);
            return $captured;
        }
        $candidates = array();
        foreach ($order->get_refunds() as $refund) {
            $created = $refund->get_date_created();
            if (
                $refund->get_refunded_payment()
                || '' !== (string) $refund->get_meta('_bmfp_refund_row', true)
                || self::amount_cents($refund) !== $cents
                || null === $created
                || $created->getTimestamp() < time() - self::FALLBACK_MAX_AGE_SECONDS
            ) {
                continue;
            }
            $candidates[] = $refund;
        }
        // Exactly one candidate, or the binding would be a guess.
        return 1 === count($candidates) ? $candidates[0] : null;
    }

    private static function amount_cents(\WC_Order_Refund $refund): int
    {
        try {
            return Money::to_cents(Money::transfer_amount((string) $refund->get_amount()));
        } catch (PaymentException) {
            return -1;
        }
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$captured = array();
    }
}
