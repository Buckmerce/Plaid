<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

/**
 * When a refund must be re-read from Plaid (reconciliation), until when it is monitored.
 * Refund events are the primary channel; these checks recover missed events. Pure.
 *
 * Plaid documents no return window for refunds (docs/api/PLAID_TRANSFER.md §2.8: the refund
 * object has no return-window field, and the refund guide gives none). A refund is an ACH credit
 * to the customer; Buckmerce therefore does not invent a network deadline and instead keeps a
 * settled refund under observation for as long as the refunded debit itself is monitored — until
 * Plaid's unauthorized return window of that debit closes (+ buffer), which is far longer than
 * the usual two-banking-day deadline for returning a credit — and at least SETTLED_DAILY_DAYS
 * after the refund was created (ADR-0021). The event stream keeps being read while any refund is
 * monitored, so a late refund.returned event is also recorded.
 */
final class RefundMonitoringPolicy
{
    /** Minimum age of an uncertain create before Plaid's refund list is consulted. */
    public const UNCERTAIN_GRACE_SECONDS = 300;
    /** An uncertain create not visible at Plaid after this long never created a refund. */
    public const UNCERTAIN_VOID_AFTER_SECONDS = 1800;
    public const UNCERTAIN_POLL_SECONDS = 300;
    public const IN_FLIGHT_POLL_SECONDS = 6 * HOUR_IN_SECONDS;
    /** Daily checks during the first days after the refund was created (settlement + early returns). */
    public const SETTLED_DAILY_DAYS = 14;
    public const SETTLED_POLL_SECONDS = DAY_IN_SECONDS;
    /** Afterwards weekly checks until the monitoring horizon. */
    public const SETTLED_SLOW_POLL_SECONDS = 7 * DAY_IN_SECONDS;

    /**
     * @param int|null $created_at    Refund reservation time (UTC timestamp).
     * @param int|null $debit_horizon End of the refunded debit's monitoring (its unauthorized return window + buffer).
     * @return array{next:int|null, until:int|null}
     */
    public static function plan(string $status, int $now, ?int $current_until = null, ?int $lease_ends_at = null, ?int $created_at = null, ?int $debit_horizon = null): array
    {
        switch ($status) {
            case RefundState::CREATING:
                return array('next' => max($now, (int) $lease_ends_at) + 60, 'until' => null);
            case RefundState::UNCERTAIN:
                return array('next' => $now + self::UNCERTAIN_POLL_SECONDS, 'until' => null);
            case RefundState::PENDING:
            case RefundState::POSTED:
                return array('next' => $now + self::IN_FLIGHT_POLL_SECONDS, 'until' => null);
            case RefundState::SETTLED:
                $daily_until = null === $created_at ? null : $created_at + self::SETTLED_DAILY_DAYS * DAY_IN_SECONDS;
                // Derived only from fixed facts, so repeated plans never push the horizon forward;
                // an existing horizon is never shortened.
                $until = max($current_until ?? 0, $daily_until ?? 0, $debit_horizon ?? 0);
                if (0 === $until) {
                    $until = $now + self::SETTLED_DAILY_DAYS * DAY_IN_SECONDS;
                }
                if ($now > $until) {
                    return array('next' => null, 'until' => null);
                }
                $step = $now < ($daily_until ?? $until) ? self::SETTLED_POLL_SECONDS : self::SETTLED_SLOW_POLL_SECONDS;
                return array('next' => min($now + $step, $until), 'until' => $until);
        }
        return array('next' => null, 'until' => null);
    }
}
