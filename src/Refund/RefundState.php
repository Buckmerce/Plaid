<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

/**
 * Refund states (docs/STATE_MACHINE.md §Refunds, ADR-0016). A refund is its own
 * lifecycle; it never changes the payment state machine.
 *
 * Local states exist only around the create call:
 *  - creating:  reserved; /transfer/refund/create may be in flight;
 *  - uncertain: the create outcome is unknown (timeout, 5xx) — resolved from Plaid, never retried blindly;
 *  - rejected:  Plaid definitively refused the create request — no refund exists;
 *  - void:      an uncertain create was proven not to have created a refund.
 * The other states are Plaid's refund statuses (docs/api/api/products/transfer/refunds.md).
 */
final class RefundState
{
    public const CREATING = 'creating';
    public const UNCERTAIN = 'uncertain';
    public const REJECTED = 'rejected';
    public const VOID = 'void';
    public const PENDING = 'pending';
    public const POSTED = 'posted';
    public const SETTLED = 'settled';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'returned';

    /** Explicitly allowed transitions. Anything not listed fails closed. */
    public const TRANSITIONS = array(
        self::CREATING => array(self::PENDING, self::POSTED, self::SETTLED, self::FAILED, self::CANCELLED, self::RETURNED, self::UNCERTAIN, self::REJECTED),
        self::UNCERTAIN => array(self::PENDING, self::POSTED, self::SETTLED, self::FAILED, self::CANCELLED, self::RETURNED, self::VOID),
        self::PENDING => array(self::POSTED, self::SETTLED, self::FAILED, self::CANCELLED, self::RETURNED),
        self::POSTED => array(self::SETTLED, self::FAILED, self::RETURNED),
        self::SETTLED => array(self::RETURNED),
        self::REJECTED => array(),
        self::VOID => array(),
        self::FAILED => array(),
        self::CANCELLED => array(),
        self::RETURNED => array(),
    );

    /** Monotonic progress of a refund that exists at Plaid. */
    public const RANK = array(self::PENDING => 1, self::POSTED => 2, self::SETTLED => 3);

    /** Refunds that reduce the refundable amount: money left or may still leave the merchant. */
    public const ACTIVE = array(self::CREATING, self::UNCERTAIN, self::PENDING, self::POSTED, self::SETTLED);

    /** Refunds that exist (or may exist) at Plaid and count toward Plaid's limit of refunds per transfer. */
    public const COUNTED = array(self::CREATING, self::UNCERTAIN, self::PENDING, self::POSTED, self::SETTLED, self::FAILED, self::CANCELLED, self::RETURNED);

    /** Statuses reported by Plaid. */
    public const PROVIDER = array(self::PENDING, self::POSTED, self::SETTLED, self::FAILED, self::CANCELLED, self::RETURNED);

    private function __construct()
    {
    }

    public static function is_known(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function is_terminal(string $state): bool
    {
        return in_array($state, array(self::REJECTED, self::VOID, self::FAILED, self::CANCELLED, self::RETURNED), true);
    }

    public static function is_active(string $state): bool
    {
        return in_array($state, self::ACTIVE, true);
    }

    /** Outcomes that mean the customer did not (or no longer) receive the refunded money. */
    public static function is_failure(string $state): bool
    {
        return in_array($state, array(self::FAILED, self::RETURNED), true);
    }
}
