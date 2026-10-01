<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Persistence\EventCursor;
use PayBridge\Plaid\Persistence\PaymentEpoch;
use PayBridge\Plaid\Settings\AccountIdentity;
use PayBridge\Plaid\Settings\AccountScope;
use PayBridge\Plaid\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class PaymentEpochTest extends TestCase
{
    private AccountScope $sandbox_a;

    protected function setUp(): void
    {
        \PayBridgeTestStore::reset();
        $this->sandbox_a = new AccountScope('sandbox', AccountIdentity::fingerprint('client-a'));
    }

    public function test_without_any_intent_every_event_predates_the_store(): void
    {
        self::assertNull(PaymentEpoch::get($this->sandbox_a));
        self::assertTrue(PaymentEpoch::predates($this->sandbox_a, gmdate('Y-m-d\TH:i:s\Z')), 'No intent was ever created, so no transfer can be ours.');
    }

    public function test_mark_is_written_once_and_never_moves_forward(): void
    {
        self::assertTrue(PaymentEpoch::mark($this->sandbox_a));
        $epoch = PaymentEpoch::get($this->sandbox_a);
        self::assertNotNull($epoch);
        \PayBridgeTestStore::$options[PaymentEpoch::option_name($this->sandbox_a)] = (string) ($epoch - 500);
        self::assertTrue(PaymentEpoch::mark($this->sandbox_a));
        self::assertSame($epoch - 500, PaymentEpoch::get($this->sandbox_a), 'A later first payment never replaces the earliest epoch.');
        self::assertNull(PaymentEpoch::get(new AccountScope('production', $this->sandbox_a->account_fp)), 'Each environment has its own epoch.');
    }

    public function test_each_plaid_account_has_its_own_epoch(): void
    {
        $production_a = new AccountScope('production', AccountIdentity::fingerprint('client-a'));
        $production_b = new AccountScope('production', AccountIdentity::fingerprint('client-b'));
        \PayBridgeTestStore::$options[PaymentEpoch::option_name($production_a)] = (string) (time() - 400 * DAY_IN_SECONDS);
        self::assertNull(PaymentEpoch::get($production_b), 'A new account in the same environment starts without an epoch.');
        self::assertTrue(PaymentEpoch::predates($production_b, gmdate('Y-m-d\TH:i:s\Z', time() - 10 * DAY_IN_SECONDS)), 'Account B never inherits account A\'s epoch.');
        self::assertTrue(PaymentEpoch::mark($production_b));
        self::assertGreaterThan((int) PaymentEpoch::get($production_a), (int) PaymentEpoch::get($production_b));
        self::assertSame(time() - 400 * DAY_IN_SECONDS, PaymentEpoch::get($production_a), 'Account A\'s epoch is preserved for audit.');
    }

    public function test_adopt_only_fills_a_missing_epoch(): void
    {
        PaymentEpoch::adopt($this->sandbox_a, 1000);
        self::assertSame(1000, PaymentEpoch::get($this->sandbox_a));
        PaymentEpoch::adopt($this->sandbox_a, 5);
        self::assertSame(1000, PaymentEpoch::get($this->sandbox_a), 'Migration never overwrites an existing epoch.');
    }

    public function test_invalid_scopes_never_store_anything(): void
    {
        foreach (array(new AccountScope('sandbox', ''), new AccountScope('sandbox', AccountScope::LEGACY), new AccountScope('staging', $this->sandbox_a->account_fp)) as $scope) {
            self::assertFalse(PaymentEpoch::mark($scope), 'An epoch needs a real account and environment: payments fail closed.');
            self::assertNull(PaymentEpoch::get($scope));
        }
        self::assertSame(array(), \PayBridgeTestStore::$options);
    }

    public function test_only_events_clearly_older_than_the_epoch_are_skipped(): void
    {
        PaymentEpoch::mark($this->sandbox_a);
        $epoch = (int) PaymentEpoch::get($this->sandbox_a);
        $at = static fn (int $time): string => gmdate('Y-m-d\TH:i:s\Z', $time);
        self::assertTrue(PaymentEpoch::predates($this->sandbox_a, $at($epoch - PaymentEpoch::CLOCK_TOLERANCE_SECONDS - 60)));
        self::assertFalse(PaymentEpoch::predates($this->sandbox_a, $at($epoch - 600)), 'Clock skew between Plaid and the store is tolerated.');
        self::assertFalse(PaymentEpoch::predates($this->sandbox_a, $at($epoch + 60)));
        self::assertFalse(PaymentEpoch::predates($this->sandbox_a, ''), 'Events without a timestamp are classified normally.');
        self::assertFalse(PaymentEpoch::predates($this->sandbox_a, 'not a date'));
    }

    public function test_event_cursor_is_scoped_by_account(): void
    {
        $cursor = new EventCursor();
        $production_a = new AccountScope('production', AccountIdentity::fingerprint('client-a'));
        $production_b = new AccountScope('production', AccountIdentity::fingerprint('client-b'));
        $cursor->advance($production_a, '1000');
        self::assertSame('1000', $cursor->get($production_a));
        self::assertSame('0', $cursor->get($production_b), 'A new Plaid account starts its own stream at 0.');
        $cursor->advance($production_b, '3');
        self::assertSame('1000', $cursor->get($production_a), 'Account A\'s cursor is preserved for audit.');
        $cursor->advance($production_a, '999');
        self::assertSame('1000', $cursor->get($production_a), 'A cursor never moves backwards.');
        \PayBridgeTestStore::$options[EventCursor::OPTION_PREFIX . 'production'] = '5000';
        self::assertSame('3', $cursor->get($production_b), 'The schema-2 per-environment cursor is never used.');
        $rotated = Settings::from_array(array('environment' => 'production', 'client_id' => 'client-a', 'secret' => 'rotated'))->account_scope();
        self::assertTrue($rotated->equals($production_a), 'Rotating the secret keeps the same stream.');
        self::assertSame('1000', $cursor->get($rotated), 'Secret rotation never resets the cursor.');
        $this->expectException(\PayBridge\Plaid\Exception\PersistenceException::class);
        $cursor->advance(new AccountScope('production', AccountScope::LEGACY), '1');
    }
}
