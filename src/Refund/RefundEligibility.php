<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

use PayBridge\Plaid\Payment\PaymentSnapshot;

/** Result of RefundPolicy::evaluate(). Amounts are two-decimal strings. */
final class RefundEligibility
{
    /** @param list<RefundRecord> $records Refunds of the current transfer. */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly string $transfer_id,
        public readonly ?PaymentSnapshot $snapshot,
        public readonly array $records,
        public readonly string $refunded,
        public readonly string $remaining
    ) {
    }

    /** @param list<RefundRecord> $records */
    public static function allowed(string $transfer_id, PaymentSnapshot $snapshot, array $records, string $refunded, string $remaining): self
    {
        return new self(true, 'allowed', '', $transfer_id, $snapshot, $records, $refunded, $remaining);
    }

    /** @param list<RefundRecord> $records */
    public static function denied(string $code, string $message, string $transfer_id = '', ?PaymentSnapshot $snapshot = null, array $records = array(), string $refunded = '0.00'): self
    {
        return new self(false, $code, $message, $transfer_id, $snapshot, $records, $refunded, '0.00');
    }
}
