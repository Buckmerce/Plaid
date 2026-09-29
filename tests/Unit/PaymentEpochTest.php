<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Persistence\PaymentEpoch;
use PHPUnit\Framework\TestCase;

final class PaymentEpochTest extends TestCase
{
    protected function setUp(): void
    {
        \PayBridgeTestStore::reset();
    }

    public function test_without_any_intent_every_event_predates_the_store(): void
    {
        self::assertNull(PaymentEpoch::get('sandbox'));
        self::assertTrue(PaymentEpoch::predates('sandbox', gmdate('Y-m-d\TH:i:s\Z')), 'No intent was ever created, so no transfer can be ours.');
    }

    public function test_mark_is_written_once_and_never_moves_forward(): void
    {
        self::assertTrue(PaymentEpoch::mark('sandbox'));
        $epoch = PaymentEpoch::get('sandbox');
        self::assertNotNull($epoch);
        \PayBridgeTestStore::$options[PaymentEpoch::OPTION_PREFIX . 'sandbox'] = (string) ($epoch - 500);
        self::assertTrue(PaymentEpoch::mark('sandbox'));
        self::assertSame($epoch - 500, PaymentEpoch::get('sandbox'), 'A later first payment never replaces the earliest epoch.');
        self::assertNull(PaymentEpoch::get('production'), 'Each environment has its own epoch.');
    }

    public function test_only_events_clearly_older_than_the_epoch_are_skipped(): void
    {
        PaymentEpoch::mark('sandbox');
        $epoch = (int) PaymentEpoch::get('sandbox');
        $at = static fn (int $time): string => gmdate('Y-m-d\TH:i:s\Z', $time);
        self::assertTrue(PaymentEpoch::predates('sandbox', $at($epoch - PaymentEpoch::CLOCK_TOLERANCE_SECONDS - 60)));
        self::assertFalse(PaymentEpoch::predates('sandbox', $at($epoch - 600)), 'Clock skew between Plaid and the store is tolerated.');
        self::assertFalse(PaymentEpoch::predates('sandbox', $at($epoch + 60)));
        self::assertFalse(PaymentEpoch::predates('sandbox', ''), 'Events without a timestamp are classified normally.');
        self::assertFalse(PaymentEpoch::predates('sandbox', 'not a date'));
    }
}
