<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

/**
 * When a refund must be re-read from Plaid (reconciliation), until when it is monitored.
 * Refund events are the primary channel; these checks recover missed events. Pure.
 */
final class RefundMonitoringPolicy
{
    /** Minimum age of an uncertain create before Plaid's refund list is consulted. */
    public const UNCERTAIN_GRACE_SECONDS = 300;
    /** An uncertain create not visible at Plaid after this long never created a refund. */
    public const UNCERTAIN_VOID_AFTER_SECONDS = 1800;
    public const UNCERTAIN_POLL_SECONDS = 300;
    public const IN_FLIGHT_POLL_SECONDS = 6 * HOUR_IN_SECONDS;
    public const SETTLED_POLL_SECONDS = DAY_IN_SECONDS;
    /** A settled refund (an ACH credit) can still be returned for a few banking days. */
    public const SETTLED_MONITOR_DAYS = 10;

    /** @return array{next:int|null, until:int|null} */
    public static function plan(string $status, int $now, ?int $current_until = null, ?int $lease_ends_at = null): array
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
                $until = $current_until ?? $now + self::SETTLED_MONITOR_DAYS * DAY_IN_SECONDS;
                return $now > $until ? array('next' => null, 'until' => null) : array('next' => min($now + self::SETTLED_POLL_SECONDS, $until), 'until' => $until);
        }
        return array('next' => null, 'until' => null);
    }
}
