<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Payment\PaymentSnapshot;
use PHPUnit\Framework\TestCase;

final class PaymentSnapshotTest extends TestCase
{
    public function test_round_trip_is_lossless_and_fingerprint_stable(): void
    {
        $snapshot = PaymentSnapshot::create(42, '11.11', 'USD', 'sandbox');
        $copy = PaymentSnapshot::from_json($snapshot->to_json());
        self::assertNotNull($copy);
        self::assertSame($snapshot->to_array(), $copy->to_array());
        self::assertSame($snapshot->fingerprint(), $copy->fingerprint());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $snapshot->attempt_id);
    }

    public function test_attempt_ids_are_unique(): void
    {
        self::assertNotSame(PaymentSnapshot::create(1, '1.00', 'USD', 'sandbox')->attempt_id, PaymentSnapshot::create(1, '1.00', 'USD', 'sandbox')->attempt_id);
    }

    public function test_invalid_or_tampered_json_is_rejected(): void
    {
        $data = PaymentSnapshot::create(7, '22.22', 'USD', 'production')->to_array();
        self::assertNull(PaymentSnapshot::from_json(''));
        self::assertNull(PaymentSnapshot::from_json('{'));
        foreach (array(
            array('amount' => '22.2'),
            array('amount' => '0.00'),
            array('amount' => 22.22),
            array('currency' => 'EUR'),
            array('environment' => 'development'),
            array('gateway_id' => 'another_gateway'),
            array('order_id' => '7'),
            array('schema_version' => 2),
            array('attempt_id' => 'x'),
        ) as $override) {
            self::assertNull(PaymentSnapshot::from_array($override + $data), (string) json_encode($override));
        }
    }

    public function test_matches_compares_exact_money_currency_and_environment(): void
    {
        $snapshot = PaymentSnapshot::create(9, '33.33', 'USD', 'sandbox');
        self::assertTrue($snapshot->matches(9, '33.330', 'usd', 'sandbox'));
        self::assertFalse($snapshot->matches(9, '33.34', 'USD', 'sandbox'));
        self::assertFalse($snapshot->matches(10, '33.33', 'USD', 'sandbox'));
        self::assertFalse($snapshot->matches(9, '33.33', 'USD', 'production'));
    }
}
