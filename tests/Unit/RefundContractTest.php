<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Plaid\Client\PlaidResponse;
use PayBridge\Plaid\Plaid\DTO\Transfer;
use PayBridge\Plaid\Plaid\DTO\TransferEvent;
use PayBridge\Plaid\Plaid\DTO\TransferRefund;
use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use PayBridge\Plaid\Plaid\Refund\TransferRefundService;
use PayBridge\Plaid\Plaid\Transfer\TransferService;
use PayBridge\Plaid\Refund\RefundMonitoringPolicy;
use PayBridge\Plaid\Refund\RefundState;
use PayBridge\Plaid\Tests\Support\FakePlaidClient;
use PHPUnit\Framework\TestCase;

/** Plaid refund, transfer-window and refund-event contracts (docs/api/PLAID_TRANSFER.md). */
final class RefundContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function refund(array $overrides = array()): array
    {
        return $overrides + array('id' => 'r-1', 'transfer_id' => 't-1', 'amount' => '12.34', 'status' => 'pending', 'created' => '2026-09-30T10:00:00Z', 'failure_reason' => null, 'network_trace_id' => null);
    }

    public function test_refund_create_sends_the_documented_fields_and_validates_the_response(): void
    {
        $client = (new FakePlaidClient())->on('/transfer/refund/create', static fn (array $body): PlaidResponse => new PlaidResponse(array('refund' => self::refund(), 'request_id' => 'req'), 'req'));
        $refund = (new TransferRefundService($client))->create('t-1', '12.34', 'pbfp-key');
        self::assertSame('r-1', $refund->id);
        self::assertSame('pending', $refund->status);
        self::assertSame(array('transfer_id' => 't-1', 'amount' => '12.34', 'idempotency_key' => 'pbfp-key'), $client->calls[0]['body']);
    }

    public function test_refund_create_rejects_a_response_for_another_transfer_or_amount(): void
    {
        foreach (array(array('transfer_id' => 't-2'), array('amount' => '12.35')) as $override) {
            $client = (new FakePlaidClient())->on('/transfer/refund/create', static fn (): PlaidResponse => new PlaidResponse(array('refund' => self::refund($override)), 'req'));
            try {
                (new TransferRefundService($client))->create('t-1', '12.34', 'pbfp-key');
                self::fail('Mismatched refund must not be accepted.');
            } catch (PlaidMalformedResponseException $exception) {
                self::assertSame('plaid_malformed_response', $exception->safe_code());
            }
        }
    }

    public function test_idempotency_key_limits_are_enforced_before_calling_plaid(): void
    {
        $client = new FakePlaidClient();
        foreach (array('', str_repeat('k', 51)) as $key) {
            try {
                (new TransferRefundService($client))->create('t-1', '1.00', $key);
                self::fail('Invalid key must be refused.');
            } catch (\InvalidArgumentException) {
                self::assertSame(array(), $client->calls);
            }
        }
    }

    public function test_refund_get_and_cancel(): void
    {
        $client = (new FakePlaidClient())
            ->on('/transfer/refund/get', static fn (): PlaidResponse => new PlaidResponse(array('refund' => self::refund(array('status' => 'returned', 'failure_reason' => array('failure_code' => 'R01', 'description' => 'NSF')))), 'g'))
            ->on('/transfer/refund/cancel', static fn (): PlaidResponse => new PlaidResponse(array('request_id' => 'c'), 'c'));
        $service = new TransferRefundService($client);
        $refund = $service->get('r-1');
        self::assertSame('returned', $refund->status);
        self::assertSame('R01', $refund->failure_code);
        self::assertSame('c', $service->cancel('r-1'));
        self::assertSame(array('refund_id' => 'r-1'), $client->calls[1]['body']);
    }

    public function test_unknown_refund_status_is_malformed(): void
    {
        $this->expectException(PlaidMalformedResponseException::class);
        TransferRefund::from_array(self::refund(array('status' => 'refunded')), 'r');
    }

    public function test_transfer_get_exposes_return_windows_cancellable_and_refunds(): void
    {
        $data = array(
            'id' => 't-1', 'status' => 'funds_available', 'type' => 'debit', 'amount' => '33.33', 'iso_currency_code' => 'USD', 'created' => '2026-09-01T12:00:00Z',
            'standard_return_window' => '2026-09-06', 'unauthorized_return_window' => '2026-11-30', 'expected_funds_available_date' => '2026-09-05', 'cancellable' => false,
            'refunds' => array(self::refund(array('transfer_id' => 't-1', 'amount' => '10.00', 'status' => 'settled'))),
            'failure_reason' => null, 'metadata' => null,
        );
        $client = (new FakePlaidClient())->on('/transfer/get', static fn (): PlaidResponse => new PlaidResponse(array('transfer' => $data), 'x'));
        $transfer = (new TransferService($client))->get('t-1');
        self::assertSame('2026-09-06', $transfer->standard_return_window);
        self::assertSame('2026-11-30', $transfer->unauthorized_return_window);
        self::assertSame('2026-09-05', $transfer->expected_funds_available_date);
        self::assertSame('2026-09-01T12:00:00Z', $transfer->created);
        self::assertFalse($transfer->cancellable);
        self::assertCount(1, $transfer->refunds);
        self::assertSame('10.00', $transfer->refunds[0]->amount);

        $invalid = Transfer::from_array(array('unauthorized_return_window' => '2026-13-45', 'cancellable' => 'yes') + $data, 'x');
        self::assertSame('', $invalid->unauthorized_return_window, 'An impossible date is dropped, never trusted.');
        self::assertFalse($invalid->cancellable, 'Only a real boolean true means cancellable.');
    }

    public function test_refund_events_never_drive_the_payment_state(): void
    {
        $base = array('event_id' => 7, 'event_type' => 'refund.settled', 'transfer_id' => 't-1', 'transfer_amount' => '5.00', 'refund_id' => 'r-9', 'timestamp' => '2026-09-30T10:00:00Z');
        $event = TransferEvent::from_array($base, 'x');
        self::assertTrue($event->is_refund_event());
        self::assertFalse($event->is_lifecycle_event(), 'A refund event carries the original transfer_id but is not a payment event.');
        self::assertTrue($event->is_processable());
        self::assertSame('settled', $event->refund_status());

        $unprefixed = TransferEvent::from_array(array('event_type' => 'returned') + $base, 'x');
        self::assertFalse($unprefixed->is_lifecycle_event(), 'Defensive: any event with a refund_id is a refund event.');
        self::assertSame('returned', $unprefixed->refund_status());

        $swept = TransferEvent::from_array(array('event_type' => 'refund.swept') + $base, 'x');
        self::assertFalse($swept->is_processable(), 'Ledger sweep bookkeeping of refunds changes no refund status.');

        $payment = TransferEvent::from_array(array('event_type' => 'returned', 'refund_id' => null) + $base, 'x');
        self::assertTrue($payment->is_lifecycle_event());
        self::assertSame('', $payment->refund_status());

        foreach (array('adjustment', 'guaranteed', 'swept', 'sweep.settled') as $type) {
            self::assertFalse(TransferEvent::from_array(array('event_type' => $type, 'refund_id' => null) + $base, 'x')->is_processable(), $type);
        }
    }

    public function test_refund_monitoring_plan(): void
    {
        $now = 1790000000;
        self::assertSame($now + 300, RefundMonitoringPolicy::plan(RefundState::UNCERTAIN, $now)['next']);
        self::assertSame($now + 6 * HOUR_IN_SECONDS, RefundMonitoringPolicy::plan(RefundState::PENDING, $now)['next']);
        $settled = RefundMonitoringPolicy::plan(RefundState::SETTLED, $now);
        self::assertSame($now + 14 * DAY_IN_SECONDS, $settled['until'], 'Without any facts a settled refund is watched daily for two weeks.');
        self::assertSame(array('next' => null, 'until' => null), RefundMonitoringPolicy::plan(RefundState::SETTLED, $now, $now - 1));

        // Plaid documents no refund return window: a settled refund is watched as long as the refunded debit.
        $debit_horizon = $now + 60 * DAY_IN_SECONDS;
        $fresh = RefundMonitoringPolicy::plan(RefundState::SETTLED, $now, null, null, $now - DAY_IN_SECONDS, $debit_horizon);
        self::assertSame(array('next' => $now + DAY_IN_SECONDS, 'until' => $debit_horizon), $fresh, 'Daily while young, until the debit\'s unauthorized return window closes.');
        $older = RefundMonitoringPolicy::plan(RefundState::SETTLED, $now, $debit_horizon, null, $now - 20 * DAY_IN_SECONDS, $debit_horizon);
        self::assertSame(array('next' => $now + 7 * DAY_IN_SECONDS, 'until' => $debit_horizon), $older, 'Weekly afterwards.');
        $late = RefundMonitoringPolicy::plan(RefundState::SETTLED, $now, null, null, $now - 2 * DAY_IN_SECONDS, $now - DAY_IN_SECONDS);
        self::assertSame($now + 12 * DAY_IN_SECONDS, $late['until'], 'A refund issued after the debit window closed is still watched for two weeks.');
        self::assertSame($debit_horizon, RefundMonitoringPolicy::plan(RefundState::SETTLED, $now, $debit_horizon, null, $now - DAY_IN_SECONDS, $now)['until'], 'The horizon is never shortened.');
        self::assertSame(array('next' => null, 'until' => null), RefundMonitoringPolicy::plan(RefundState::SETTLED, $debit_horizon + 1, $debit_horizon, null, $now - DAY_IN_SECONDS, $debit_horizon), 'Monitoring ends at the horizon.');
        foreach (array(RefundState::FAILED, RefundState::RETURNED, RefundState::CANCELLED, RefundState::REJECTED, RefundState::VOID) as $terminal) {
            self::assertSame(array('next' => null, 'until' => null), RefundMonitoringPolicy::plan($terminal, $now), $terminal);
        }
        self::assertSame($now + 120 + 60, RefundMonitoringPolicy::plan(RefundState::CREATING, $now, null, $now + 120)['next'], 'A live creation is checked after its lease.');
    }
}
