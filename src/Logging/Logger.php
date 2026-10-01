<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Logging;

use Buckmerce\Plaid\Settings\Settings;

/** Structured WooCommerce logger with mandatory recursive redaction. */
final class Logger
{
    public const SOURCE = 'buckmerce-for-plaid';

    /** @param array<string, mixed> $context */
    public function log(string $level, string $event, array $context = array()): void
    {
        if (in_array($level, array( 'debug', 'info' ), true) && ! Settings::load()->debug()) {
            return;
        }
        if (! function_exists('wc_get_logger')) {
            return;
        }
        $safe = Redactor::redact($context);
        $safe = is_array($safe) ? $safe : array();
        $safe['source'] = self::SOURCE;
        $safe['event'] = $event;
        wc_get_logger()->log($level, $event, $safe);
    }

    /** @param array<string, mixed> $context */
    public function security(string $event, array $context = array()): void
    {
        $this->log('warning', $event, $context);
    }

    /** Non-reversible short correlation value for data that must not be logged verbatim. */
    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
