<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Settings;

use Buckmerce\Plaid\Plaid\PlaidEnvironment;

/**
 * Identity of one Plaid event stream: environment + non-secret account fingerprint.
 *
 * /transfer/event/sync returns the events of the authenticated Plaid client. Event IDs, the
 * sync cursor, the payment epoch and refund identities therefore belong to one account in one
 * environment; the environment name alone does not identify them (two Production accounts are
 * both "production"). Rotating the secret keeps the Client ID and therefore the same scope.
 */
final class AccountScope
{
    /**
     * Rows written before schema 3 whose Plaid account cannot be proven. They are kept for
     * auditing but never claimed, deduplicated or cursor-advanced as another account's data.
     */
    public const LEGACY = 'legacy';

    public function __construct(public readonly string $environment, public readonly string $account_fp)
    {
    }

    public static function from_settings(Settings $settings): self
    {
        return new self($settings->environment_name(), $settings->account_fingerprint());
    }

    /** A usable scope: a known environment and a real account fingerprint (not legacy, not empty). */
    public function is_valid(): bool
    {
        return PlaidEnvironment::is_valid($this->environment) && self::is_fingerprint($this->account_fp);
    }

    /** Stable, option-name-safe key, e.g. "production_0123456789abcdef". */
    public function key(): string
    {
        return $this->environment . '_' . $this->account_fp;
    }

    public function equals(self $other): bool
    {
        return $this->environment === $other->environment && $this->account_fp === $other->account_fp;
    }

    public static function is_fingerprint(string $value): bool
    {
        return 1 === preg_match('/^[a-f0-9]{16}$/', $value);
    }
}
