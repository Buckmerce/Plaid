<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

/** Outcome of ReturnRetryPolicy for one order. Immutable value object. */
final class ReturnRetryDecision
{
    /** No transfer of the order was returned: the policy does not restrict new attempts. */
    public const NOT_RETURNED = 'not_returned';
    public const ALLOW_RETRY_1 = 'allow_retry_1';
    public const ALLOW_RETRY_2 = 'allow_retry_2';
    /** A return code other than R01/R09 (e.g. R10, R07, R11, R02) or an unknown code. */
    public const BLOCK_RETURN_CODE = 'block_return_code';
    /** Plaid allows at most two retries of a returned transfer. */
    public const BLOCK_RETRY_LIMIT = 'block_retry_limit';
    /** Retries are allowed only within 180 days of the original transfer's creation. */
    public const BLOCK_WINDOW_EXPIRED = 'block_window_expired';
    /** Eligible under Plaid's rules, but the origination flow cannot mark a debit as a retry. */
    public const BLOCK_UNSUPPORTED_FLOW = 'block_unsupported_flow';

    public function __construct(
        public readonly string $outcome,
        public readonly string $return_code = '',
        public readonly string $original_transfer_id = '',
        public readonly int $retries_used = 0,
        public readonly ?int $window_ends_at = null
    ) {
    }

    /** Whether a new bank debit may be originated for the order. */
    public function allows_new_debit(): bool
    {
        return in_array($this->outcome, array(self::NOT_RETURNED, self::ALLOW_RETRY_1, self::ALLOW_RETRY_2), true);
    }

    public function is_blocked(): bool
    {
        return ! $this->allows_new_debit();
    }

    /** The /transfer/create description Plaid requires for a retry ("Retry 1" / "Retry 2"), '' otherwise. */
    public function retry_description(): string
    {
        return match ($this->outcome) {
            self::ALLOW_RETRY_1 => 'Retry 1',
            self::ALLOW_RETRY_2 => 'Retry 2',
            default => '',
        };
    }
}
