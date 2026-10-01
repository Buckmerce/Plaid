<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Payment\PaymentStateMachine;
use Buckmerce\Plaid\Refund\RefundState;
use Buckmerce\Plaid\Refund\RefundStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Explicit expected decision for every provider-state ordering that events and reconciliation
 * can produce (docs/STATE_MACHINE.md §Ordering matrix): APPLY, NOOP, STALE or CONFLICT.
 */
final class StateOrderingMatrixTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function payment_orderings(): iterable
    {
        $apply = PaymentStateMachine::APPLY;
        $noop = PaymentStateMachine::NOOP;
        $stale = PaymentStateMachine::STALE;
        $conflict = PaymentStateMachine::CONFLICT;
        $rows = array(
            array(PaymentState::PENDING, PaymentState::POSTED, $apply),
            array(PaymentState::POSTED, PaymentState::PENDING, $stale),
            array(PaymentState::SETTLED, PaymentState::POSTED, $stale),
            array(PaymentState::SETTLED, PaymentState::FUNDS_AVAILABLE, $apply),
            array(PaymentState::FUNDS_AVAILABLE, PaymentState::SETTLED, $stale),
            array(PaymentState::FUNDS_AVAILABLE, PaymentState::RETURNED, $apply),
            array(PaymentState::POSTED, PaymentState::RETURNED, $apply),
            array(PaymentState::SETTLED, PaymentState::RETURNED, $apply),
            array(PaymentState::FAILED, PaymentState::PENDING, $stale),
            array(PaymentState::RETURNED, PaymentState::POSTED, $stale),
            array(PaymentState::RETURNED, PaymentState::FUNDS_AVAILABLE, $stale),
            array(PaymentState::RETURNED, PaymentState::RETURNED, $noop),
            array(PaymentState::RETURNED, PaymentState::FAILED, $conflict),
            array(PaymentState::FAILED, PaymentState::RETURNED, $conflict),
            array(PaymentState::FUNDS_AVAILABLE, PaymentState::FAILED, $conflict),
            array(PaymentState::PENDING, PaymentState::FAILED, $apply),
            array(PaymentState::PENDING, PaymentState::CANCELLED, $apply),
            array(PaymentState::POSTED, PaymentState::CANCELLED, $conflict),
            array(PaymentState::PENDING, PaymentState::FUNDS_AVAILABLE, $apply),
            array(PaymentState::TRANSFER_CREATED, PaymentState::RETURNED, $apply),
            array(PaymentState::MANUAL_REVIEW, PaymentState::FUNDS_AVAILABLE, $stale),
            array(PaymentState::MANUAL_REVIEW, PaymentState::RETURNED, $apply),
            array(PaymentState::CANCELLED, PaymentState::SETTLED, $stale),
            array(PaymentState::PENDING, PaymentState::PENDING, $noop),
        );
        foreach ($rows as [$from, $to, $expected]) {
            yield $from . ' → ' . $to => array($from, $to, $expected);
        }
    }

    #[DataProvider('payment_orderings')]
    public function test_payment_ordering(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, PaymentStateMachine::decide($from, $to));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refund_orderings(): iterable
    {
        $apply = PaymentStateMachine::APPLY;
        $noop = PaymentStateMachine::NOOP;
        $stale = PaymentStateMachine::STALE;
        $conflict = PaymentStateMachine::CONFLICT;
        $rows = array(
            array(RefundState::CREATING, RefundState::PENDING, $apply),
            array(RefundState::CREATING, RefundState::UNCERTAIN, $apply),
            array(RefundState::CREATING, RefundState::REJECTED, $apply),
            array(RefundState::UNCERTAIN, RefundState::PENDING, $apply),
            array(RefundState::UNCERTAIN, RefundState::VOID, $apply),
            array(RefundState::PENDING, RefundState::POSTED, $apply),
            array(RefundState::POSTED, RefundState::PENDING, $stale),
            array(RefundState::POSTED, RefundState::SETTLED, $apply),
            array(RefundState::SETTLED, RefundState::POSTED, $stale),
            array(RefundState::SETTLED, RefundState::RETURNED, $apply),
            array(RefundState::PENDING, RefundState::SETTLED, $apply),
            array(RefundState::PENDING, RefundState::FAILED, $apply),
            array(RefundState::PENDING, RefundState::CANCELLED, $apply),
            array(RefundState::POSTED, RefundState::CANCELLED, $conflict),
            array(RefundState::SETTLED, RefundState::FAILED, $conflict),
            array(RefundState::FAILED, RefundState::PENDING, $stale),
            array(RefundState::FAILED, RefundState::SETTLED, $stale),
            array(RefundState::RETURNED, RefundState::POSTED, $stale),
            array(RefundState::RETURNED, RefundState::RETURNED, $noop),
            array(RefundState::CANCELLED, RefundState::POSTED, $stale),
            array(RefundState::REJECTED, RefundState::PENDING, $stale),
            array(RefundState::VOID, RefundState::SETTLED, $stale),
            array(RefundState::RETURNED, RefundState::FAILED, $conflict),
            array(RefundState::PENDING, RefundState::CREATING, $conflict),
            array(RefundState::PENDING, 'refunded', $conflict),
        );
        foreach ($rows as [$from, $to, $expected]) {
            yield $from . ' → ' . $to => array($from, $to, $expected);
        }
    }

    #[DataProvider('refund_orderings')]
    public function test_refund_ordering(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, RefundStateMachine::decide($from, $to));
    }

    public function test_every_documented_refund_edge_applies_and_nothing_else_does(): void
    {
        foreach (array_keys(RefundState::TRANSITIONS) as $from) {
            foreach (array_keys(RefundState::TRANSITIONS) as $to) {
                $documented = in_array($to, RefundState::TRANSITIONS[$from], true);
                self::assertSame($documented, PaymentStateMachine::APPLY === RefundStateMachine::decide($from, $to), $from . ' -> ' . $to);
            }
        }
    }

    public function test_refund_state_groups(): void
    {
        foreach (RefundState::ACTIVE as $state) {
            self::assertFalse(RefundState::is_terminal($state), $state);
        }
        foreach (array(RefundState::FAILED, RefundState::RETURNED) as $state) {
            self::assertTrue(RefundState::is_failure($state));
            self::assertFalse(RefundState::is_active($state), 'A failed or returned refund does not reduce the refundable amount.');
        }
        self::assertFalse(RefundState::is_active(RefundState::VOID));
        self::assertTrue(RefundState::is_active(RefundState::UNCERTAIN), 'An unconfirmed refund may have left the merchant: it counts until proven otherwise.');
        self::assertNotContains(RefundState::REJECTED, RefundState::COUNTED, 'A rejected create never created a Plaid refund.');
    }
}
