<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Exception\InvalidPaymentStateTransition;

/** The only component that decides whether a payment state change is allowed. */
final class PaymentStateMachine
{
    /** The transition is valid and must be applied. */
    public const APPLY = 'apply';
    /** Same state again (duplicate/replayed evidence): harmless no-op. */
    public const NOOP = 'noop';
    /** Older/weaker evidence arriving after newer state: ignored, never regresses. */
    public const STALE = 'stale';
    /** Contradictory evidence that is neither allowed nor merely stale. */
    public const CONFLICT = 'conflict';

    public static function decide(string $from, string $to): string
    {
        if (! PaymentState::is_known($from) || ! PaymentState::is_known($to) || PaymentState::NEW === $to) {
            return self::CONFLICT;
        }
        if ($from === $to) {
            return self::NOOP;
        }
        if (in_array($to, PaymentState::TRANSITIONS[$from], true)) {
            return self::APPLY;
        }
        $from_rank = PaymentState::LIFECYCLE_RANK[$from] ?? null;
        $to_rank = PaymentState::LIFECYCLE_RANK[$to] ?? null;
        if (null !== $to_rank && null !== $from_rank && $to_rank < $from_rank) {
            return self::STALE;
        }
        if (null !== $to_rank && (PaymentState::is_terminal($from) || PaymentState::MANUAL_REVIEW === $from)) {
            // A success-lifecycle signal after a failure/return/quarantine never revives the payment.
            return self::STALE;
        }
        return self::CONFLICT;
    }

    /** @throws InvalidPaymentStateTransition */
    public static function assert_transition(string $from, string $to): void
    {
        if (self::APPLY !== self::decide($from, $to)) {
            throw new InvalidPaymentStateTransition(sprintf('Payment state transition %s -> %s is not allowed.', esc_html('' === $from ? 'new' : $from), esc_html($to)));
        }
    }
}
