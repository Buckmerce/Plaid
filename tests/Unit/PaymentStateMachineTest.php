<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Exception\InvalidPaymentStateTransition;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Payment\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentStateMachineTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function allowed_edges(): iterable
    {
        foreach (PaymentState::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                yield ('' === $from ? 'new' : $from) . ' -> ' . $to => array($from, $to);
            }
        }
    }

    #[DataProvider('allowed_edges')]
    public function test_every_documented_edge_applies(string $from, string $to): void
    {
        self::assertSame(PaymentStateMachine::APPLY, PaymentStateMachine::decide($from, $to));
        PaymentStateMachine::assert_transition($from, $to);
    }

    public function test_every_undocumented_edge_does_not_apply(): void
    {
        foreach (PaymentState::all() as $from) {
            foreach (PaymentState::all() as $to) {
                if (in_array($to, PaymentState::TRANSITIONS[$from], true)) {
                    continue;
                }
                self::assertNotSame(PaymentStateMachine::APPLY, PaymentStateMachine::decide($from, $to), $from . ' -> ' . $to);
            }
        }
    }

    public function test_same_state_replay_is_noop(): void
    {
        foreach (array(PaymentState::PENDING, PaymentState::FUNDS_AVAILABLE, PaymentState::RETURNED, PaymentState::FAILED) as $state) {
            self::assertSame(PaymentStateMachine::NOOP, PaymentStateMachine::decide($state, $state));
        }
    }

    public function test_out_of_order_lifecycle_events_are_stale_and_never_regress(): void
    {
        self::assertSame(PaymentStateMachine::STALE, PaymentStateMachine::decide(PaymentState::SETTLED, PaymentState::POSTED));
        self::assertSame(PaymentStateMachine::STALE, PaymentStateMachine::decide(PaymentState::FUNDS_AVAILABLE, PaymentState::PENDING));
        self::assertSame(PaymentStateMachine::STALE, PaymentStateMachine::decide(PaymentState::POSTED, PaymentState::TRANSFER_CREATED));
    }

    public function test_success_after_failure_or_return_is_never_applied(): void
    {
        foreach (array(PaymentState::FAILED, PaymentState::CANCELLED, PaymentState::RETURNED, PaymentState::MANUAL_REVIEW) as $negative) {
            foreach (array(PaymentState::PENDING, PaymentState::POSTED, PaymentState::SETTLED, PaymentState::FUNDS_AVAILABLE) as $success) {
                self::assertSame(PaymentStateMachine::STALE, PaymentStateMachine::decide($negative, $success), $negative . ' -> ' . $success);
            }
        }
    }

    public function test_return_after_funds_available_applies(): void
    {
        self::assertSame(PaymentStateMachine::APPLY, PaymentStateMachine::decide(PaymentState::FUNDS_AVAILABLE, PaymentState::RETURNED));
    }

    public function test_failed_after_pending_applies_and_failed_after_settlement_does_not(): void
    {
        self::assertSame(PaymentStateMachine::APPLY, PaymentStateMachine::decide(PaymentState::PENDING, PaymentState::FAILED));
        self::assertSame(PaymentStateMachine::CONFLICT, PaymentStateMachine::decide(PaymentState::SETTLED, PaymentState::FAILED));
        self::assertSame(PaymentStateMachine::CONFLICT, PaymentStateMachine::decide(PaymentState::RETURNED, PaymentState::FAILED));
    }

    public function test_unknown_states_are_conflicts(): void
    {
        self::assertSame(PaymentStateMachine::CONFLICT, PaymentStateMachine::decide(PaymentState::PENDING, 'paid'));
        self::assertSame(PaymentStateMachine::CONFLICT, PaymentStateMachine::decide('invoice_created', PaymentState::PENDING));
        self::assertSame(PaymentStateMachine::CONFLICT, PaymentStateMachine::decide(PaymentState::PENDING, PaymentState::NEW));
    }

    public function test_assert_transition_rejects_invalid_edges(): void
    {
        $this->expectException(InvalidPaymentStateTransition::class);
        PaymentStateMachine::assert_transition(PaymentState::RETURNED, PaymentState::FUNDS_AVAILABLE);
    }

    public function test_plaid_status_mapping_is_complete(): void
    {
        foreach (array('pending', 'posted', 'settled', 'funds_available', 'failed', 'cancelled', 'returned') as $status) {
            self::assertNotNull(PaymentState::from_plaid_transfer_status($status), $status);
        }
        self::assertNull(PaymentState::from_plaid_transfer_status('swept'));
        self::assertNull(PaymentState::from_plaid_transfer_status('sweep.settled'));
    }
}
