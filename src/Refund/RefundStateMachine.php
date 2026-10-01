<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

use Buckmerce\Plaid\Payment\PaymentStateMachine;

/**
 * The only component that decides whether a refund state change is allowed.
 * Decisions use the payment state machine's vocabulary (APPLY, NOOP, STALE, CONFLICT).
 */
final class RefundStateMachine
{
    public static function decide(string $from, string $to): string
    {
        if (! RefundState::is_known($from) || ! RefundState::is_known($to) || RefundState::CREATING === $to) {
            return PaymentStateMachine::CONFLICT;
        }
        if ($from === $to) {
            return PaymentStateMachine::NOOP;
        }
        if (in_array($to, RefundState::TRANSITIONS[$from], true)) {
            return PaymentStateMachine::APPLY;
        }
        $from_rank = RefundState::RANK[$from] ?? null;
        $to_rank = RefundState::RANK[$to] ?? null;
        if (null !== $to_rank && null !== $from_rank && $to_rank < $from_rank) {
            // e.g. a late "posted" after "settled".
            return PaymentStateMachine::STALE;
        }
        if (null !== $to_rank && RefundState::is_terminal($from)) {
            // Progress evidence after a final outcome never revives the refund.
            return PaymentStateMachine::STALE;
        }
        return PaymentStateMachine::CONFLICT;
    }
}
