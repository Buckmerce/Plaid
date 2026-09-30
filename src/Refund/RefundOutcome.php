<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

/** Result of a refund request, as reported back to WooCommerce. The message is admin-facing and sanitized. */
final class RefundOutcome
{
    private function __construct(public readonly bool $ok, public readonly string $message, public readonly ?RefundRecord $record)
    {
    }

    public static function success(RefundRecord $record): self
    {
        return new self(true, '', $record);
    }

    public static function error(string $message, ?RefundRecord $record = null): self
    {
        return new self(false, $message, $record);
    }
}
