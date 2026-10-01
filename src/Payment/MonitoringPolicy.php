<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

/**
 * When a payment's provider state must be re-read, and until when it is monitored at all
 * (ADR-0014). Pure: every input is explicit.
 *
 * Monitoring follows the Plaid transfer, never the WooCommerce order date:
 *  - an unused Transfer Intent is checked while a Link token for it can still be used;
 *  - money in flight (pending/posted) is checked until the transfer reaches a final state;
 *  - a settled / funds-available debit can still be returned. Plaid reports the date after
 *    which no standard return (R01, R02, R03, R29) and no unauthorized return (R05, R07,
 *    R10, R11, …) can happen; monitoring continues until the later one has passed. Without
 *    those dates a conservative fallback from the settlement (or transfer creation) applies.
 *
 * Event sync is the primary channel for late returns; these checks are the safety net.
 */
final class MonitoringPolicy
{
    public const INTENT_POLL_SECONDS = 900;
    /** An intent that never received a Link token cannot be authorized; stop after this. */
    public const UNUSED_INTENT_SECONDS = DAY_IN_SECONDS;
    public const IN_FLIGHT_POLL_SECONDS = HOUR_IN_SECONDS;
    public const IN_FLIGHT_SLOW_POLL_SECONDS = 6 * HOUR_IN_SECONDS;
    public const IN_FLIGHT_SLOW_AFTER_SECONDS = 7 * DAY_IN_SECONDS;
    public const STANDARD_WINDOW_POLL_SECONDS = 6 * HOUR_IN_SECONDS;
    public const UNAUTHORIZED_WINDOW_POLL_SECONDS = DAY_IN_SECONDS;
    /** Fallbacks when Plaid does not report the windows: 3 business days / 61 business days, rounded up generously. */
    public const FALLBACK_STANDARD_WINDOW_DAYS = 7;
    public const FALLBACK_UNAUTHORIZED_WINDOW_DAYS = 95;
    /** Extra time after a reported window date before monitoring ends (late event delivery, clock skew). */
    public const WINDOW_BUFFER_DAYS = 2;

    /**
     * @param array{state:string, now:int, attempt_created_at?:int|null, link_window_ends_at?:int|null, transfer_created_at?:int|null, settled_at?:int|null, standard_return_window?:string, unauthorized_return_window?:string, has_transfer?:bool} $input
     * @return array{next:int|null, until:int|null} next = when to re-read Plaid (null = no scheduled check); until = end of monitoring.
     */
    public static function plan(array $input): array
    {
        $state = $input['state'];
        $now = $input['now'];
        switch ($state) {
            case PaymentState::INTENT_CREATED:
            case PaymentState::INTENT_PENDING:
                $window = $input['link_window_ends_at'] ?? null;
                $end = null !== $window
                    ? $window + PaymentAttemptService::AUTHORIZATION_GRACE_SECONDS
                    : (int) ($input['attempt_created_at'] ?? $now) + self::UNUSED_INTENT_SECONDS;
                // One more check after the window closed catches a transfer created at the last moment.
                $until = $end + self::INTENT_POLL_SECONDS;
                return $now <= $until ? array('next' => $now + self::INTENT_POLL_SECONDS, 'until' => $until) : array('next' => null, 'until' => null);
            case PaymentState::TRANSFER_CREATED:
            case PaymentState::PENDING:
            case PaymentState::POSTED:
                // Money is in flight: never stop, only slow down.
                $age = $now - (int) ($input['transfer_created_at'] ?? $input['attempt_created_at'] ?? $now);
                $poll = $age > self::IN_FLIGHT_SLOW_AFTER_SECONDS ? self::IN_FLIGHT_SLOW_POLL_SECONDS : self::IN_FLIGHT_POLL_SECONDS;
                return array('next' => $now + $poll, 'until' => null);
            case PaymentState::SETTLED:
            case PaymentState::FUNDS_AVAILABLE:
                return self::return_window_plan($input, false);
            case PaymentState::MANUAL_REVIEW:
                // A quarantined transfer can still fail or be returned; without a transfer nothing can change remotely.
                return true === ($input['has_transfer'] ?? false) ? self::return_window_plan($input, true) : array('next' => null, 'until' => null);
        }
        // failed, cancelled, returned, intent_failed, intent_uncertain, new: nothing left to watch.
        return array('next' => null, 'until' => null);
    }

    /**
     * @param array{now:int, attempt_created_at?:int|null, transfer_created_at?:int|null, settled_at?:int|null, standard_return_window?:string, unauthorized_return_window?:string} $input
     * @return array{next:int|null, until:int|null}
     */
    private static function return_window_plan(array $input, bool $daily): array
    {
        $now = $input['now'];
        [$standard_end, $unauthorized_end] = self::return_windows($input);
        if ($now > $unauthorized_end) {
            return array('next' => null, 'until' => null);
        }
        $poll = (! $daily && $now <= $standard_end) ? self::STANDARD_WINDOW_POLL_SECONDS : self::UNAUTHORIZED_WINDOW_POLL_SECONDS;
        return array('next' => min($now + $poll, $unauthorized_end), 'until' => $unauthorized_end);
    }

    /**
     * End (UTC timestamps) of the standard and the unauthorized ACH return window.
     *
     * @param array{now:int, attempt_created_at?:int|null, transfer_created_at?:int|null, settled_at?:int|null, standard_return_window?:string, unauthorized_return_window?:string} $input
     * @return array{int, int}
     */
    public static function return_windows(array $input): array
    {
        $base = $input['settled_at'] ?? $input['transfer_created_at'] ?? $input['attempt_created_at'] ?? $input['now'];
        $buffer = self::WINDOW_BUFFER_DAYS * DAY_IN_SECONDS;
        $standard = self::end_of_date($input['standard_return_window'] ?? '');
        $unauthorized = self::end_of_date($input['unauthorized_return_window'] ?? '');
        $standard_end = null !== $standard ? $standard + $buffer : $base + self::FALLBACK_STANDARD_WINDOW_DAYS * DAY_IN_SECONDS;
        $unauthorized_end = null !== $unauthorized ? $unauthorized + $buffer : $base + self::FALLBACK_UNAUTHORIZED_WINDOW_DAYS * DAY_IN_SECONDS;
        return array($standard_end, max($standard_end, $unauthorized_end));
    }

    /** Last second (UTC) of a YYYY-MM-DD date, or null when the value is not such a date. */
    public static function end_of_date(string $date): ?int
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $timestamp = strtotime($date . 'T23:59:59Z');
        return false === $timestamp ? null : $timestamp;
    }
}
