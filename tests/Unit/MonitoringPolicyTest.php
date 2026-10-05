<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Payment\MonitoringPolicy;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentMonitor;
use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Payment\PaymentState;
use PHPUnit\Framework\TestCase;

/** Return-window based monitoring: follows the Plaid transfer, never the order date. */
final class MonitoringPolicyTest extends TestCase
{
    private const NOW = 1790000000; // 2026-09-21T…Z

    public function test_old_order_with_a_new_transfer_is_still_monitored(): void
    {
        // The WooCommerce order is a year old (order-pay retry), the transfer settled yesterday.
        $plan = MonitoringPolicy::plan(array(
            'state' => PaymentState::FUNDS_AVAILABLE,
            'now' => self::NOW,
            'attempt_created_at' => self::NOW - 2 * DAY_IN_SECONDS,
            'transfer_created_at' => self::NOW - 2 * DAY_IN_SECONDS,
            'settled_at' => self::NOW - DAY_IN_SECONDS,
        ));
        self::assertNotNull($plan['next']);
        self::assertGreaterThan(self::NOW + 80 * DAY_IN_SECONDS, (int) $plan['until'], 'Without provider windows the conservative fallback (95 days after settlement) applies.');
    }

    public function test_funds_available_with_an_open_unauthorized_window_is_monitored_daily(): void
    {
        $input = array(
            'state' => PaymentState::FUNDS_AVAILABLE,
            'now' => self::NOW,
            'settled_at' => self::NOW - 10 * DAY_IN_SECONDS,
            'standard_return_window' => gmdate('Y-m-d', self::NOW - 5 * DAY_IN_SECONDS),
            'unauthorized_return_window' => gmdate('Y-m-d', self::NOW + 40 * DAY_IN_SECONDS),
        );
        $plan = MonitoringPolicy::plan($input);
        self::assertSame(self::NOW + DAY_IN_SECONDS, $plan['next'], 'Standard window closed: daily checks until the unauthorized window closes.');
        self::assertSame(MonitoringPolicy::end_of_date($input['unauthorized_return_window']) + 2 * DAY_IN_SECONDS, $plan['until']);
    }

    public function test_inside_the_standard_window_checks_are_more_frequent(): void
    {
        $plan = MonitoringPolicy::plan(array(
            'state' => PaymentState::SETTLED,
            'now' => self::NOW,
            'settled_at' => self::NOW - HOUR_IN_SECONDS,
            'standard_return_window' => gmdate('Y-m-d', self::NOW + 3 * DAY_IN_SECONDS),
            'unauthorized_return_window' => gmdate('Y-m-d', self::NOW + 85 * DAY_IN_SECONDS),
        ));
        self::assertSame(self::NOW + 6 * HOUR_IN_SECONDS, $plan['next']);
    }

    public function test_expired_return_window_leaves_intensive_reconciliation(): void
    {
        foreach (array(PaymentState::FUNDS_AVAILABLE, PaymentState::SETTLED) as $state) {
            $plan = MonitoringPolicy::plan(array(
                'state' => $state,
                'now' => self::NOW,
                'settled_at' => self::NOW - 200 * DAY_IN_SECONDS,
                'standard_return_window' => gmdate('Y-m-d', self::NOW - 195 * DAY_IN_SECONDS),
                'unauthorized_return_window' => gmdate('Y-m-d', self::NOW - 110 * DAY_IN_SECONDS),
            ));
            self::assertSame(array('next' => null, 'until' => null), $plan, $state);
        }
    }

    public function test_missing_provider_windows_use_the_conservative_fallback(): void
    {
        $settled = self::NOW - 90 * DAY_IN_SECONDS;
        $still = MonitoringPolicy::plan(array('state' => PaymentState::FUNDS_AVAILABLE, 'now' => self::NOW, 'settled_at' => $settled));
        self::assertNotNull($still['next'], '90 days after settlement the 95-day fallback is still open.');
        $done = MonitoringPolicy::plan(array('state' => PaymentState::FUNDS_AVAILABLE, 'now' => self::NOW + 6 * DAY_IN_SECONDS, 'settled_at' => $settled));
        self::assertSame(array('next' => null, 'until' => null), $done);
        [$standard, $unauthorized] = MonitoringPolicy::return_windows(array('now' => self::NOW, 'transfer_created_at' => self::NOW));
        self::assertSame(self::NOW + 7 * DAY_IN_SECONDS, $standard, 'Without a settlement time the transfer creation is the base.');
        self::assertSame(self::NOW + 95 * DAY_IN_SECONDS, $unauthorized);
    }

    public function test_invalid_provider_dates_are_ignored_not_trusted(): void
    {
        self::assertNull(MonitoringPolicy::end_of_date('2026-02-30'));
        self::assertNull(MonitoringPolicy::end_of_date('tomorrow'));
        self::assertSame(strtotime('2026-10-07T23:59:59Z'), MonitoringPolicy::end_of_date('2026-10-07'));
        $plan = MonitoringPolicy::plan(array('state' => PaymentState::FUNDS_AVAILABLE, 'now' => self::NOW, 'settled_at' => self::NOW, 'unauthorized_return_window' => 'garbage'));
        self::assertSame(self::NOW + 95 * DAY_IN_SECONDS, $plan['until']);
    }

    public function test_money_in_flight_is_never_abandoned(): void
    {
        foreach (array(PaymentState::TRANSFER_CREATED, PaymentState::PENDING, PaymentState::POSTED) as $state) {
            $fresh = MonitoringPolicy::plan(array('state' => $state, 'now' => self::NOW, 'transfer_created_at' => self::NOW - HOUR_IN_SECONDS));
            self::assertSame(self::NOW + HOUR_IN_SECONDS, $fresh['next'], $state);
            self::assertNull($fresh['until'], $state . ' has no end while money moves.');
            $old = MonitoringPolicy::plan(array('state' => $state, 'now' => self::NOW, 'transfer_created_at' => self::NOW - 400 * DAY_IN_SECONDS));
            self::assertSame(self::NOW + 6 * HOUR_IN_SECONDS, $old['next'], 'A very old pending transfer is still checked, only less often.');
        }
    }

    public function test_intents_are_checked_only_while_a_link_token_can_be_used(): void
    {
        $open = MonitoringPolicy::plan(array('state' => PaymentState::INTENT_PENDING, 'now' => self::NOW, 'link_window_ends_at' => self::NOW + HOUR_IN_SECONDS));
        self::assertSame(self::NOW + 900, $open['next']);
        $closed = MonitoringPolicy::plan(array('state' => PaymentState::INTENT_PENDING, 'now' => self::NOW, 'link_window_ends_at' => self::NOW - 3 * HOUR_IN_SECONDS));
        self::assertSame(array('next' => null, 'until' => null), $closed, 'After the authorization window no transfer can be created.');
        $unused = MonitoringPolicy::plan(array('state' => PaymentState::INTENT_CREATED, 'now' => self::NOW, 'attempt_created_at' => self::NOW - 2 * DAY_IN_SECONDS));
        self::assertSame(array('next' => null, 'until' => null), $unused, 'An intent that never got a Link token stops after a day.');
    }

    public function test_terminal_states_are_not_monitored_and_manual_review_follows_its_transfer(): void
    {
        foreach (array(PaymentState::FAILED, PaymentState::CANCELLED, PaymentState::RETURNED, PaymentState::INTENT_FAILED, PaymentState::INTENT_UNCERTAIN, PaymentState::NEW) as $state) {
            self::assertSame(array('next' => null, 'until' => null), MonitoringPolicy::plan(array('state' => $state, 'now' => self::NOW)), $state);
        }
        self::assertSame(array('next' => null, 'until' => null), MonitoringPolicy::plan(array('state' => PaymentState::MANUAL_REVIEW, 'now' => self::NOW, 'has_transfer' => false)));
        $review = MonitoringPolicy::plan(array('state' => PaymentState::MANUAL_REVIEW, 'now' => self::NOW, 'has_transfer' => true, 'settled_at' => self::NOW));
        self::assertSame(self::NOW + DAY_IN_SECONDS, $review['next'], 'A quarantined transfer can still be returned: daily checks.');
    }

    public function test_monitor_input_is_read_from_order_meta(): void
    {
        $snapshot = PaymentSnapshot::create(1001, '11.11', 'USD', 'sandbox');
        $order = new \WC_Order(array(
            OrderMeta::TRANSFER_ID => 't-1',
            OrderMeta::SETTLED_AT => '2026-09-20T10:00:00+00:00',
            OrderMeta::UNAUTHORIZED_RETURN_WINDOW => '2026-12-15',
            OrderMeta::STANDARD_RETURN_WINDOW => '2026-09-23',
            OrderMeta::TRANSFER_CREATED_AT => '2026-09-18T10:00:00+00:00',
        ));
        $input = PaymentMonitor::input($order, PaymentState::FUNDS_AVAILABLE, $snapshot, self::NOW);
        self::assertTrue($input['has_transfer']);
        self::assertSame(strtotime('2026-09-20T10:00:00Z'), $input['settled_at']);
        self::assertSame('2026-12-15', $input['unauthorized_return_window']);
        self::assertNull($input['link_window_ends_at']);
    }
}
