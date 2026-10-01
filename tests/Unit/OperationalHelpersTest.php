<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Admin\ConnectionTester;
use Buckmerce\Plaid\Admin\OrderListColumn;
use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\ReconciliationService;
use Buckmerce\Plaid\Exception\ConfigurationException;
use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Payment\AttemptHistory;
use Buckmerce\Plaid\Payment\PaymentAlerts;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Exception\PlaidNetworkException;
use Buckmerce\Plaid\Support\Money;
use Buckmerce\Plaid\Settings\AccountIdentity;
use Buckmerce\Plaid\Settings\AccountScope;
use PHPUnit\Framework\TestCase;

final class OperationalHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        \BuckmerceTestStore::reset();
    }

    public function test_money_cents_arithmetic_is_exact(): void
    {
        self::assertSame(1234, Money::to_cents('12.34'));
        self::assertSame(1230, Money::to_cents('12.3'));
        self::assertSame(1200, Money::to_cents('12'));
        self::assertSame('0.30', Money::sum(array('0.10', '0.20')), 'The classic float trap 0.1 + 0.2.');
        self::assertSame('100.00', Money::sum(array('33.33', '33.33', '33.34')));
        self::assertSame('0.01', Money::remaining('100.00', '99.99'));
        self::assertSame('0.00', Money::remaining('10.00', '12.00'), 'Never negative.');
        self::assertSame('0.05', Money::from_cents(5));
        self::assertSame('1234567.89', Money::from_cents(123456789));
        foreach (array('1.001', '-1.00', 'abc', '1e3', '9999999999999.00') as $invalid) {
            try {
                Money::to_cents($invalid);
                self::fail('Rejected: ' . $invalid);
            } catch (PaymentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_connection_errors_are_classified_for_the_merchant(): void
    {
        $api = static fn (int $status, string $type, string $code): PlaidApiException => new PlaidApiException($status, $type, $code, 'x');
        self::assertSame(ConnectionTester::INVALID_CREDENTIALS, ConnectionTester::classify($api(400, 'INVALID_INPUT', 'INVALID_API_KEYS')));
        self::assertSame(ConnectionTester::INVALID_CREDENTIALS, ConnectionTester::classify($api(400, 'INVALID_INPUT', 'UNAUTHORIZED_ENVIRONMENT')));
        self::assertSame(ConnectionTester::PRODUCT_NOT_ENABLED, ConnectionTester::classify($api(400, 'INVALID_INPUT', 'INVALID_PRODUCT')));
        self::assertSame(ConnectionTester::PERMISSION_DENIED, ConnectionTester::classify($api(400, 'INVALID_INPUT', 'UNAUTHORIZED_ROUTE_ACCESS')));
        self::assertSame(ConnectionTester::RATE_LIMITED, ConnectionTester::classify($api(429, 'RATE_LIMIT_EXCEEDED', 'RATE_LIMIT')));
        self::assertSame(ConnectionTester::PLAID_UNAVAILABLE, ConnectionTester::classify($api(500, 'API_ERROR', 'INTERNAL_SERVER_ERROR')));
        self::assertSame(ConnectionTester::PLAID_UNAVAILABLE, ConnectionTester::classify($api(503, 'API_ERROR', 'PLANNED_MAINTENANCE')));
        self::assertSame(ConnectionTester::REJECTED, ConnectionTester::classify($api(400, 'INVALID_REQUEST', 'INVALID_FIELD')));
        $message = ConnectionTester::message(array('status' => ConnectionTester::CONNECTED, 'ledger' => 'enabled', 'issues' => array('missing_link_customization', 'funding_account_conflict')));
        self::assertStringContainsString('Link customization missing', $message);
        self::assertStringContainsString('remove the Funding Account ID', $message);
        self::assertStringContainsString('INVALID_API_KEYS', ConnectionTester::message(array('status' => ConnectionTester::INVALID_CREDENTIALS, 'error_code' => 'INVALID_API_KEYS')));
        self::assertStringNotContainsString('<', ConnectionTester::message(array('status' => ConnectionTester::REJECTED, 'error_code' => '<script>')));
    }

    public function test_background_errors_are_categorized(): void
    {
        self::assertSame('transient', ReconciliationService::category(new PlaidNetworkException('timeout')));
        self::assertSame('transient', ReconciliationService::category(new PlaidApiException(500, 'API_ERROR', 'INTERNAL_SERVER_ERROR', 'x')));
        self::assertSame('transient', ReconciliationService::category(new PlaidApiException(429, 'RATE_LIMIT_EXCEEDED', 'RATE_LIMIT', 'x')));
        self::assertSame('permanent', ReconciliationService::category(new PlaidApiException(400, 'INVALID_INPUT', 'INVALID_API_KEYS', 'x')));
        self::assertSame('permanent', ReconciliationService::category(new ConfigurationException('no keys')));
        self::assertSame('local', ReconciliationService::category(new \RuntimeException('db')));
    }

    public function test_event_sync_retries_use_bounded_exponential_backoff(): void
    {
        $scope = new AccountScope('production', AccountIdentity::fingerprint('client-a'));
        $other = new AccountScope('production', AccountIdentity::fingerprint('client-b'));
        self::assertSame(60, EventSyncService::retry_delay($scope), 'Healthy: continue promptly.');
        $delays = array();
        foreach (array(1, 2, 3, 4, 5, 6, 9, 50) as $failures) {
            update_option(EventSyncService::HEALTH_OPTION_PREFIX . $scope->key(), array('failures' => $failures));
            $delays[] = EventSyncService::retry_delay($scope);
        }
        self::assertSame(array(60, 120, 240, 480, 900, 900, 900, 900), $delays);
        self::assertSame(60, EventSyncService::retry_delay($other), 'Failures of one Plaid account never slow down another account\'s stream.');
        self::assertNotSame(EventSyncService::mutex_resource($scope), EventSyncService::mutex_resource($other), 'Each account stream has its own event-sync lock.');
    }

    public function test_attempt_history_never_drops_money_moving_attempts(): void
    {
        $history = array();
        for ($i = 0; $i < 30; ++$i) {
            $history[] = array('attempt_id' => 'a' . $i, 'transfer_id' => 0 === $i % 10 ? 't' . $i : '');
        }
        $kept = AttemptHistory::retain($history);
        $transfers = array_values(array_filter(array_column($kept, 'transfer_id')));
        self::assertSame(array('t0', 't10', 't20'), $transfers, 'Attempts with a transfer are kept forever.');
        self::assertCount(AttemptHistory::MAX_WITHOUT_TRANSFER + 3, $kept);
        self::assertSame('a29', $kept[count($kept) - 1]['attempt_id'], 'The newest attempts are kept.');
        self::assertContains(\Buckmerce\Plaid\Payment\OrderMeta::RETURN_CODE, AttemptHistory::ATTEMPT_KEYS, 'Per-attempt meta never leaks into the next attempt.');
        self::assertNotContains(\Buckmerce\Plaid\Payment\OrderMeta::RETIRED_ATTEMPTS, AttemptHistory::ATTEMPT_KEYS);
    }

    public function test_order_list_badges_make_returns_explicit(): void
    {
        self::assertSame(array('Bank payment returned (R01)', 'critical'), OrderListColumn::badge(PaymentState::RETURNED, 'R01'));
        self::assertSame(array('Bank payment returned', 'critical'), OrderListColumn::badge(PaymentState::RETURNED, ''));
        self::assertSame('warning', OrderListColumn::badge(PaymentState::MANUAL_REVIEW, '')[1]);
        self::assertSame(array('', ''), OrderListColumn::badge('', ''));
    }

    public function test_alert_messages_are_explicit_and_carry_no_markup(): void
    {
        $message = PaymentAlerts::message(array('type' => PaymentAlerts::RETURNED_AFTER_REFUND, 'order_number' => '123', 'code' => 'R01', 'amount' => '10.00'));
        self::assertStringContainsString('AFTER a refund', $message);
        self::assertStringContainsString('#123', $message);
        self::assertStringContainsString('lose both', $message);
        self::assertStringContainsString('$10.00', PaymentAlerts::message(array('type' => PaymentAlerts::REFUND_FAILED, 'order_number' => '9', 'code' => 'R03', 'amount' => '10.00')));
        foreach (PaymentAlerts::CRITICAL as $type) {
            self::assertNotSame('', PaymentAlerts::message(array('type' => $type, 'order_number' => '1', 'code' => '', 'amount' => '')));
        }
    }
}
