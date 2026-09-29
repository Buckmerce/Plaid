<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

/**
 * The moment this store created its first Transfer Intent in an environment.
 *
 * /transfer/event/sync returns the whole history of the Plaid account, which can
 * belong to other stores and integrations. An event older than the epoch cannot
 * belong to a PayBridge transfer of this store, so it is classified without an
 * extra /transfer/get call (ADR-0011). The epoch is written before the first
 * remote intent creation; if it cannot be written, no intent is created.
 */
final class PaymentEpoch
{
    public const OPTION_PREFIX = 'paybridge_plaid_first_intent_at_';
    /** Allowance for the difference between Plaid event timestamps and the local clock. */
    public const CLOCK_TOLERANCE_SECONDS = 3600;

    /** @return bool False only when the epoch could not be stored (callers fail closed). */
    public static function mark(string $environment): bool
    {
        if (null !== self::get($environment)) {
            return true;
        }
        // add_option() never overwrites: concurrent first payments keep the earliest value.
        add_option(self::OPTION_PREFIX . $environment, (string) time(), '', false);
        return null !== self::get($environment);
    }

    /** @phpstan-impure Reads a stored option that another process may have written. */
    public static function get(string $environment): ?int
    {
        $value = get_option(self::OPTION_PREFIX . $environment, false);
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** True only when the event certainly happened before this store's first intent. */
    public static function predates(string $environment, string $event_timestamp): bool
    {
        $epoch = self::get($environment);
        if (null === $epoch) {
            // No intent was ever created here, so no transfer can be ours.
            return true;
        }
        $at = '' === $event_timestamp ? false : strtotime($event_timestamp);
        return false !== $at && $at < $epoch - self::CLOCK_TOLERANCE_SECONDS;
    }
}
