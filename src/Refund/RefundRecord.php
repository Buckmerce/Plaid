<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

use Buckmerce\Plaid\Settings\AccountScope;

/** One row of {prefix}buckmerce_plaid_refunds. */
final class RefundRecord
{
    public const ORIGIN_WOOCOMMERCE = 'woocommerce';
    /** Created outside Buckmerce (e.g. in the Plaid Dashboard) and discovered from Plaid. */
    public const ORIGIN_EXTERNAL = 'external';

    public function __construct(
        public readonly int $id,
        public readonly int $order_id,
        public readonly int $wc_refund_id,
        public readonly string $environment,
        public readonly string $account_fp,
        public readonly string $attempt_id,
        public readonly string $transfer_id,
        public readonly string $refund_id,
        public readonly string $idempotency_key,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $origin,
        public readonly string $failure_code,
        public readonly string $request_id,
        public readonly string $owner_token,
        public readonly string $lease_expires_at,
        public readonly string $reconcile_after,
        public readonly string $monitor_until,
        public readonly int $checks,
        public readonly string $created_at,
        public readonly string $updated_at
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function from_row(array $row): self
    {
        $string = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        return new self(
            (int) $string('id'),
            (int) $string('order_id'),
            (int) $string('wc_refund_id'),
            $string('environment'),
            $string('account_fp'),
            $string('attempt_id'),
            $string('transfer_id'),
            $string('refund_id'),
            $string('idempotency_key'),
            $string('amount'),
            $string('currency'),
            $string('status'),
            $string('origin'),
            $string('failure_code'),
            $string('request_id'),
            $string('owner_token'),
            $string('lease_expires_at'),
            $string('reconcile_after'),
            $string('monitor_until'),
            (int) $string('checks'),
            $string('created_at'),
            $string('updated_at')
        );
    }

    /** The Plaid account and environment that own this refund. */
    public function scope(): AccountScope
    {
        return new AccountScope($this->environment, $this->account_fp);
    }

    /** Seconds since the row was reserved (UTC). */
    public function age(?int $now = null): int
    {
        $created = strtotime($this->created_at . ' UTC');
        return false === $created ? 0 : max(0, ($now ?? time()) - $created);
    }

    public function lease_is_live(?int $now = null): bool
    {
        $lease = '' === $this->lease_expires_at ? false : strtotime($this->lease_expires_at . ' UTC');
        return false !== $lease && $lease > ($now ?? time());
    }
}
