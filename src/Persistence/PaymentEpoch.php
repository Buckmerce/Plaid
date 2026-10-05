<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Persistence;

use Buckmerce\Plaid\Settings\AccountScope;

/**
 * The moment this store created its first Transfer Intent with one Plaid account in one
 * environment.
 *
 * /transfer/event/sync returns the whole history of the Plaid account, which can belong to
 * other stores and integrations. An event older than the epoch cannot belong to a Buckmerce
 * transfer of this store, so it is classified without an extra /transfer/get call. The epoch
 * is written before the first remote intent creation; if it cannot be written, no intent is
 * created. Another account (even in the same environment) has its own epoch: a new account's
 * history is never mistaken for, or hidden by, the previous account's.
 */
final class PaymentEpoch
{
    /** Schema-2 per-environment options (kept for auditing) use this prefix + environment. */
    public const OPTION_PREFIX = 'buckmerce_plaid_first_intent_at_';
    /** Allowance for the difference between Plaid event timestamps and the local clock. */
    public const CLOCK_TOLERANCE_SECONDS = 3600;

    public static function option_name(AccountScope $scope): string
    {
        return self::OPTION_PREFIX . $scope->key();
    }

    /** @return bool False only when the epoch could not be stored (callers fail closed). */
    public static function mark(AccountScope $scope): bool
    {
        if (! $scope->is_valid()) {
            return false;
        }
        if (null !== self::get($scope)) {
            return true;
        }
        // add_option() never overwrites: concurrent first payments keep the earliest value.
        add_option(self::option_name($scope), (string) time(), '', false);
        return null !== self::get($scope);
    }

    /**
     * Records a known earlier epoch (schema migration). Never overwrites an existing value, so it
     * can only make the epoch earlier-or-equal, which is always safe (fewer events are skipped).
     */
    public static function adopt(AccountScope $scope, int $timestamp): void
    {
        if ($scope->is_valid() && $timestamp > 0 && null === self::get($scope)) {
            add_option(self::option_name($scope), (string) $timestamp, '', false);
        }
    }

    /** @phpstan-impure Reads a stored option that another process may have written. */
    public static function get(AccountScope $scope): ?int
    {
        if (! $scope->is_valid()) {
            return null;
        }
        $value = get_option(self::option_name($scope), false);
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** True only when the event certainly happened before this store's first intent with this account. */
    public static function predates(AccountScope $scope, string $event_timestamp): bool
    {
        $epoch = self::get($scope);
        if (null === $epoch) {
            // No intent was ever created with this account, so no transfer can be ours.
            return true;
        }
        $at = '' === $event_timestamp ? false : strtotime($event_timestamp);
        return false !== $at && $at < $epoch - self::CLOCK_TOLERANCE_SECONDS;
    }
}
