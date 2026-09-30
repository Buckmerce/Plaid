<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentSnapshot;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Refund\RefundPolicy;
use PayBridge\Plaid\Refund\RefundRecord;
use PayBridge\Plaid\Refund\RefundService;
use PayBridge\Plaid\Refund\RefundState;
use PayBridge\Plaid\Settings\Settings;
use PHPUnit\Framework\TestCase;

/** Refund amount integrity and eligibility (ADR-0016), from local data only. */
final class RefundPolicyTest extends TestCase
{
    private PaymentSnapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = PaymentSnapshot::create(1001, '100.00', 'USD', 'sandbox');
    }

    private function settings(string $client_id = 'client-a'): Settings
    {
        return Settings::from_array(array('environment' => 'sandbox', 'client_id' => $client_id, 'secret' => 's'));
    }

    /** @param array<string, string> $overrides */
    private function order(array $overrides = array()): \WC_Order
    {
        return new \WC_Order($overrides + array(
            OrderMeta::PAYMENT_SNAPSHOT => $this->snapshot->to_json(),
            OrderMeta::PAYMENT_STATE => PaymentState::FUNDS_AVAILABLE,
            OrderMeta::TRANSFER_ID => 'transfer-1',
            OrderMeta::TRANSFER_STATUS => 'funds_available',
            OrderMeta::ENVIRONMENT => 'sandbox',
            OrderMeta::ACCOUNT_FINGERPRINT => $this->settings()->account_fingerprint(),
            OrderMeta::TRANSFER_CREATED_AT => gmdate('c', time() - 5 * DAY_IN_SECONDS),
        ));
    }

    private function record(string $amount, string $status, string $transfer_id = 'transfer-1', string $created_at = '2026-01-01 00:00:00', string $lease = ''): RefundRecord
    {
        static $id = 0;
        ++$id;
        return RefundRecord::from_row(array('id' => $id, 'order_id' => 1001, 'transfer_id' => $transfer_id, 'amount' => $amount, 'status' => $status, 'created_at' => $created_at, 'lease_expires_at' => $lease, 'environment' => 'sandbox'));
    }

    public function test_full_refundable_amount_when_nothing_was_refunded(): void
    {
        $result = RefundPolicy::evaluate($this->order(), $this->settings(), array());
        self::assertTrue($result->allowed);
        self::assertSame('100.00', $result->remaining);
        self::assertSame('0.00', $result->refunded);
    }

    public function test_multiple_partial_refunds_reduce_the_remaining_amount_exactly(): void
    {
        $records = array($this->record('33.33', RefundState::SETTLED), $this->record('33.33', RefundState::PENDING), $this->record('0.01', RefundState::POSTED));
        $result = RefundPolicy::evaluate($this->order(), $this->settings(), $records);
        self::assertTrue($result->allowed);
        self::assertSame('66.67', $result->refunded);
        self::assertSame('33.33', $result->remaining, 'Cent arithmetic: no binary floating point.');
    }

    public function test_failed_cancelled_returned_rejected_and_void_refunds_do_not_count(): void
    {
        $records = array();
        foreach (array(RefundState::FAILED, RefundState::CANCELLED, RefundState::RETURNED, RefundState::REJECTED, RefundState::VOID) as $status) {
            $records[] = $this->record('50.00', $status);
        }
        $result = RefundPolicy::evaluate($this->order(), $this->settings(), $records);
        self::assertTrue($result->allowed);
        self::assertSame('100.00', $result->remaining);
    }

    public function test_fully_refunded_payment_cannot_be_refunded_again(): void
    {
        $result = RefundPolicy::evaluate($this->order(), $this->settings(), array($this->record('60.00', RefundState::SETTLED), $this->record('40.00', RefundState::PENDING)));
        self::assertFalse($result->allowed);
        self::assertSame('fully_refunded', $result->code);
    }

    public function test_unconfirmed_refund_blocks_any_new_refund(): void
    {
        $uncertain = RefundPolicy::evaluate($this->order(), $this->settings(), array($this->record('10.00', RefundState::UNCERTAIN)));
        self::assertFalse($uncertain->allowed);
        self::assertSame('refund_unconfirmed', $uncertain->code);
        $in_flight = RefundPolicy::evaluate($this->order(), $this->settings(), array($this->record('10.00', RefundState::CREATING, 'transfer-1', '2026-01-01 00:00:00', gmdate('Y-m-d H:i:s', time() + 60))));
        self::assertSame('refund_unconfirmed', $in_flight->code);
    }

    public function test_plaid_limit_of_ten_refunds_per_transfer(): void
    {
        $records = array();
        for ($i = 0; $i < 10; ++$i) {
            $records[] = $this->record('1.00', 0 === $i % 2 ? RefundState::SETTLED : RefundState::FAILED);
        }
        self::assertSame('refund_limit', RefundPolicy::evaluate($this->order(), $this->settings(), $records)->code);
        array_pop($records);
        $records[] = $this->record('1.00', RefundState::REJECTED);
        self::assertTrue(RefundPolicy::evaluate($this->order(), $this->settings(), $records)->allowed, 'Rejected creates never created a Plaid refund.');
    }

    public function test_only_settled_payments_of_the_current_transfer_are_refundable(): void
    {
        foreach (array('pending', 'posted', '') as $status) {
            self::assertSame('not_settled', RefundPolicy::evaluate($this->order(array(OrderMeta::TRANSFER_STATUS => $status, OrderMeta::PAYMENT_STATE => PaymentState::PENDING)), $this->settings(), array())->code, $status);
        }
        self::assertTrue(RefundPolicy::evaluate($this->order(array(OrderMeta::TRANSFER_STATUS => 'settled', OrderMeta::PAYMENT_STATE => PaymentState::SETTLED)), $this->settings(), array())->allowed);
        foreach (array(PaymentState::FAILED, PaymentState::CANCELLED, PaymentState::RETURNED) as $state) {
            self::assertSame('payment_not_refundable', RefundPolicy::evaluate($this->order(array(OrderMeta::PAYMENT_STATE => $state)), $this->settings(), array())->code, $state);
        }
        self::assertSame('no_transfer', RefundPolicy::evaluate($this->order(array(OrderMeta::TRANSFER_ID => '')), $this->settings(), array())->code);
        $other_transfer = RefundPolicy::evaluate($this->order(), $this->settings(), array($this->record('100.00', RefundState::SETTLED, 'older-attempt-transfer')));
        self::assertTrue($other_transfer->allowed, 'Refunds of an earlier (returned) attempt do not consume the current payment.');
    }

    public function test_environment_account_window_and_gateway_are_enforced(): void
    {
        self::assertSame('environment_mismatch', RefundPolicy::evaluate($this->order(), Settings::from_array(array('environment' => 'production', 'client_id' => 'client-a', 'secret' => 's')), array())->code);
        self::assertSame('account_mismatch', RefundPolicy::evaluate($this->order(), $this->settings('client-b'), array())->code);
        self::assertSame('refund_window_expired', RefundPolicy::evaluate($this->order(array(OrderMeta::TRANSFER_CREATED_AT => gmdate('c', time() - 181 * DAY_IN_SECONDS))), $this->settings(), array())->code);
        self::assertSame('not_paybridge', RefundPolicy::evaluate(new \WC_Order(array(), 'bacs'), $this->settings(), array())->code);
    }

    public function test_idempotency_key_is_deterministic_bound_and_within_plaid_limit(): void
    {
        $key = RefundService::idempotency_key($this->snapshot, 55, '10.00');
        self::assertSame($key, RefundService::idempotency_key($this->snapshot, 55, '10.00'), 'Retrying the same WooCommerce refund reuses the key.');
        self::assertLessThanOrEqual(50, strlen($key));
        self::assertMatchesRegularExpression('/^pbfp-[a-f0-9]{44}$/', $key);
        self::assertNotSame($key, RefundService::idempotency_key($this->snapshot, 56, '10.00'), 'Another WooCommerce refund is another refund.');
        self::assertNotSame($key, RefundService::idempotency_key($this->snapshot, 55, '10.01'));
        self::assertNotSame($key, RefundService::idempotency_key(PaymentSnapshot::create(1001, '100.00', 'USD', 'sandbox'), 55, '10.00'), 'Bound to the payment attempt.');
    }
}
