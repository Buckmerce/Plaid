<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/**
 * PayBridge payment states (docs/STATE_MACHINE.md). NEW is represented by an
 * absent order meta value.
 */
final class PaymentState
{
    public const NEW = '';
    public const INTENT_CREATING = 'intent_creating';
    public const INTENT_CREATED = 'intent_created';
    public const INTENT_PENDING = 'intent_pending';
    public const INTENT_FAILED = 'intent_failed';
    public const INTENT_UNCERTAIN = 'intent_uncertain';
    public const TRANSFER_CREATED = 'transfer_created';
    public const PENDING = 'pending';
    public const POSTED = 'posted';
    public const SETTLED = 'settled';
    public const FUNDS_AVAILABLE = 'funds_available';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'returned';
    public const MANUAL_REVIEW = 'manual_review';

    /** Explicitly allowed transitions. Anything not listed fails closed. */
    public const TRANSITIONS = array(
        self::NEW => array(self::INTENT_CREATING),
        self::INTENT_CREATING => array(self::INTENT_CREATED, self::INTENT_FAILED, self::INTENT_UNCERTAIN, self::MANUAL_REVIEW),
        self::INTENT_FAILED => array(self::INTENT_CREATING, self::MANUAL_REVIEW),
        self::INTENT_UNCERTAIN => array(self::INTENT_CREATING, self::INTENT_CREATED, self::MANUAL_REVIEW),
        self::INTENT_CREATED => array(self::INTENT_PENDING, self::TRANSFER_CREATED, self::INTENT_FAILED, self::MANUAL_REVIEW),
        self::INTENT_PENDING => array(self::TRANSFER_CREATED, self::INTENT_FAILED, self::MANUAL_REVIEW),
        self::TRANSFER_CREATED => array(self::PENDING, self::POSTED, self::SETTLED, self::FUNDS_AVAILABLE, self::FAILED, self::CANCELLED, self::RETURNED, self::MANUAL_REVIEW),
        self::PENDING => array(self::POSTED, self::SETTLED, self::FUNDS_AVAILABLE, self::FAILED, self::CANCELLED, self::RETURNED, self::MANUAL_REVIEW),
        self::POSTED => array(self::SETTLED, self::FUNDS_AVAILABLE, self::FAILED, self::RETURNED, self::MANUAL_REVIEW),
        self::SETTLED => array(self::FUNDS_AVAILABLE, self::RETURNED, self::MANUAL_REVIEW),
        self::FUNDS_AVAILABLE => array(self::RETURNED),
        self::FAILED => array(),
        self::CANCELLED => array(),
        self::RETURNED => array(),
        self::MANUAL_REVIEW => array(self::FAILED, self::CANCELLED, self::RETURNED),
    );

    /** Monotonic progress of a created transfer; used to recognise stale/out-of-order events. */
    public const LIFECYCLE_RANK = array(
        self::TRANSFER_CREATED => 1,
        self::PENDING => 2,
        self::POSTED => 3,
        self::SETTLED => 4,
        self::FUNDS_AVAILABLE => 5,
    );

    /** Plaid transfer status / transfer event type → PayBridge state. */
    public const FROM_PLAID_TRANSFER_STATUS = array(
        'pending' => self::PENDING,
        'posted' => self::POSTED,
        'settled' => self::SETTLED,
        'funds_available' => self::FUNDS_AVAILABLE,
        'failed' => self::FAILED,
        'cancelled' => self::CANCELLED,
        'returned' => self::RETURNED,
    );

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function is_known(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function is_terminal(string $state): bool
    {
        return in_array($state, array(self::FAILED, self::CANCELLED, self::RETURNED), true);
    }

    /** States in which a Plaid transfer exists for the active attempt. */
    public static function has_transfer(string $state): bool
    {
        return isset(self::LIFECYCLE_RANK[$state]) || in_array($state, array(self::FAILED, self::CANCELLED, self::RETURNED), true);
    }

    public static function from_plaid_transfer_status(string $status): ?string
    {
        return self::FROM_PLAID_TRANSFER_STATUS[$status] ?? null;
    }
}
