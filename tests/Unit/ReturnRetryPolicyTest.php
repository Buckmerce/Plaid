<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Payment\ReturnRetryDecision;
use Buckmerce\Plaid\Payment\ReturnRetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Plaid's rules for reprocessing returned transfers (docs/api/PLAID_TRANSFER.md §2.12, ADR-0019):
 * R01/R09 only, at most two retries, within 180 days of the original transfer, marked
 * "Retry 1"/"Retry 2" on /transfer/create. Transfer UI has no such marking, so Buckmerce 1.0
 * never re-debits a returned order.
 */
final class ReturnRetryPolicyTest extends TestCase
{
    private const NOW = 1790000000;

    /** @return array{transfer_id:string, state:string, return_code:string, created_at:string} */
    private static function transfer(string $id, string $state, string $code = '', int $age_days = 5): array
    {
        return array('transfer_id' => $id, 'state' => $state, 'return_code' => $code, 'created_at' => gmdate('c', self::NOW - $age_days * DAY_IN_SECONDS));
    }

    private static function decide(array $lineage, string $flow = ReturnRetryPolicy::FLOW_TRANSFER_CREATE): ReturnRetryDecision
    {
        return ReturnRetryPolicy::decide($lineage, $flow, self::NOW);
    }

    public function test_r01_and_r09_allow_retry_1_and_retry_2_then_stop(): void
    {
        foreach (array('R01', 'R09') as $code) {
            $original = self::transfer('t0', PaymentState::RETURNED, $code);
            $first = self::decide(array($original));
            self::assertSame(ReturnRetryDecision::ALLOW_RETRY_1, $first->outcome, $code);
            self::assertSame('Retry 1', $first->retry_description());
            self::assertSame('t0', $first->original_transfer_id);

            $second = self::decide(array($original, self::transfer('t1', PaymentState::RETURNED, $code, 2)));
            self::assertSame(ReturnRetryDecision::ALLOW_RETRY_2, $second->outcome, $code);
            self::assertSame('Retry 2', $second->retry_description());

            $third = self::decide(array($original, self::transfer('t1', PaymentState::RETURNED, $code, 3), self::transfer('t2', PaymentState::RETURNED, $code, 1)));
            self::assertSame(ReturnRetryDecision::BLOCK_RETRY_LIMIT, $third->outcome, 'A third retry is refused (' . $code . ').');
            self::assertTrue($third->is_blocked());
            self::assertSame('', $third->retry_description());
        }
    }

    public function test_codes_that_may_never_be_retried(): void
    {
        foreach (array('R10', 'R07', 'R11', 'R02', 'R03', 'R05', 'R16', 'R29', 'r10', '', 'X99') as $code) {
            $decision = self::decide(array(self::transfer('t0', PaymentState::RETURNED, $code)));
            self::assertSame(ReturnRetryDecision::BLOCK_RETURN_CODE, $decision->outcome, 'Return code "' . $code . '" is blocked.');
            self::assertFalse($decision->allows_new_debit());
        }
        self::assertSame('UNKNOWN', self::decide(array(self::transfer('t0', PaymentState::RETURNED, '')))->return_code);
        $retry_returned_unauthorized = self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R01'), self::transfer('t1', PaymentState::RETURNED, 'R10', 1)));
        self::assertSame(ReturnRetryDecision::BLOCK_RETURN_CODE, $retry_returned_unauthorized->outcome, 'A retry returned as R10 blocks any further debit.');
        self::assertSame('R10', $retry_returned_unauthorized->return_code);
    }

    public function test_180_day_window_of_the_original_transfer(): void
    {
        $inside = self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R01', 179)));
        self::assertSame(ReturnRetryDecision::ALLOW_RETRY_1, $inside->outcome);
        self::assertSame(self::NOW + DAY_IN_SECONDS, $inside->window_ends_at);
        self::assertSame(ReturnRetryDecision::BLOCK_WINDOW_EXPIRED, self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R01', 181)))->outcome);
        $unknown_age = array('transfer_id' => 't0', 'state' => PaymentState::RETURNED, 'return_code' => 'R01', 'created_at' => '');
        self::assertSame(ReturnRetryDecision::BLOCK_WINDOW_EXPIRED, self::decide(array($unknown_age))->outcome, 'An original of unknown age cannot be proven inside the window.');
        $window_from_original = self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R01', 200), self::transfer('t1', PaymentState::RETURNED, 'R01', 1)));
        self::assertSame(ReturnRetryDecision::BLOCK_WINDOW_EXPIRED, $window_from_original->outcome, 'The window counts from the ORIGINAL transfer, not the retry.');
    }

    public function test_transfer_ui_never_retries_a_returned_transfer(): void
    {
        $eligible = self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R01')), ReturnRetryPolicy::FLOW_TRANSFER_UI);
        self::assertSame(ReturnRetryDecision::BLOCK_UNSUPPORTED_FLOW, $eligible->outcome, 'Transfer UI cannot mark a debit as "Retry 1": no same-order debit.');
        self::assertTrue($eligible->is_blocked());
        self::assertSame('R01', $eligible->return_code);
        self::assertSame(ReturnRetryDecision::BLOCK_RETURN_CODE, self::decide(array(self::transfer('t0', PaymentState::RETURNED, 'R10')), ReturnRetryPolicy::FLOW_TRANSFER_UI)->outcome, 'The stricter rule is reported first.');
    }

    public function test_failures_before_money_moved_are_not_returns(): void
    {
        foreach (array(PaymentState::FAILED, PaymentState::CANCELLED, PaymentState::FUNDS_AVAILABLE, PaymentState::PENDING) as $state) {
            $decision = self::decide(array(self::transfer('t0', $state)), ReturnRetryPolicy::FLOW_TRANSFER_UI);
            self::assertSame(ReturnRetryDecision::NOT_RETURNED, $decision->outcome, $state);
            self::assertTrue($decision->allows_new_debit(), 'A normal failed or cancelled payment may be paid again (' . $state . ').');
        }
        self::assertTrue(self::decide(array(), ReturnRetryPolicy::FLOW_TRANSFER_UI)->allows_new_debit(), 'No transfer yet.');
        $failed_then_returned = self::decide(array(self::transfer('t0', PaymentState::FAILED, '', 9), self::transfer('t1', PaymentState::RETURNED, 'R01', 4)));
        self::assertSame(ReturnRetryDecision::ALLOW_RETRY_1, $failed_then_returned->outcome, 'The first RETURNED transfer is the original; earlier failures do not count as retries.');
        self::assertSame('t1', $failed_then_returned->original_transfer_id);
    }

    public function test_every_transfer_after_the_return_counts_as_a_retry(): void
    {
        // A pre-1.0 repayment after a return (paid, then returned again) used up a retry.
        $lineage = array(self::transfer('t0', PaymentState::RETURNED, 'R01', 20), self::transfer('t1', PaymentState::FAILED, '', 10));
        self::assertSame(1, self::decide($lineage)->retries_used);
        self::assertSame(ReturnRetryDecision::ALLOW_RETRY_2, self::decide($lineage)->outcome);
        $lineage[] = self::transfer('t2', PaymentState::CANCELLED, '', 5);
        self::assertSame(ReturnRetryDecision::BLOCK_RETRY_LIMIT, self::decide($lineage)->outcome);
    }
}
