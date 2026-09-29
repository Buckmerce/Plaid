<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\DTO;

use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use PayBridge\Plaid\Support\Decimal;

/** Normalized /transfer/get transfer object (fields PayBridge relies on). */
final class Transfer
{
    public const STATUSES = array('pending', 'posted', 'settled', 'funds_available', 'cancelled', 'failed', 'returned');

    /** @param array<string, string> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $type,
        public readonly string $amount,
        public readonly string $iso_currency_code,
        public readonly string $failure_code,
        public readonly string $ach_return_code,
        public readonly string $failure_description,
        public readonly array $metadata,
        public readonly string $request_id
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data, string $request_id): self
    {
        $amount = Fields::required_string($data, 'amount', $request_id);
        if ('' === Decimal::normalise($amount)) {
            throw new PlaidMalformedResponseException('Plaid transfer amount is not a decimal string.', $request_id);
        }
        $failure = Fields::optional_object($data, 'failure_reason');
        return new self(
            Fields::required_string($data, 'id', $request_id),
            Fields::enum(Fields::required_string($data, 'status', $request_id), self::STATUSES, 'status', $request_id),
            Fields::optional_string($data, 'type'),
            $amount,
            strtoupper(Fields::optional_string($data, 'iso_currency_code')),
            Fields::optional_string($failure, 'failure_code'),
            Fields::optional_string($failure, 'ach_return_code'),
            Fields::optional_string($failure, 'description'),
            Fields::string_map($data, 'metadata'),
            $request_id
        );
    }
}
