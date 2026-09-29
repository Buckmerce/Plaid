<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\DTO;

use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use PayBridge\Plaid\Support\Decimal;

/** Normalized /transfer/intent/create and /transfer/intent/get transfer_intent object. */
final class TransferIntent
{
    public const PENDING = 'PENDING';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED = 'FAILED';

    /** @param array<string, string> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $iso_currency_code,
        public readonly string $mode,
        public readonly string $transfer_id,
        public readonly string $authorization_decision,
        public readonly string $decision_rationale_code,
        public readonly string $failure_code,
        public readonly array $metadata,
        public readonly string $created,
        public readonly string $request_id
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data, string $request_id): self
    {
        $status = Fields::enum(Fields::required_string($data, 'status', $request_id), array(self::PENDING, self::SUCCEEDED, self::FAILED), 'status', $request_id);
        $amount = Fields::required_string($data, 'amount', $request_id);
        if ('' === Decimal::normalise($amount)) {
            throw new PlaidMalformedResponseException('Plaid transfer intent amount is not a decimal string.', $request_id);
        }
        $transfer_id = Fields::optional_string($data, 'transfer_id');
        if (self::SUCCEEDED === $status && '' === $transfer_id) {
            throw new PlaidMalformedResponseException('A succeeded Plaid transfer intent has no transfer_id.', $request_id);
        }
        $rationale = Fields::optional_object($data, 'authorization_decision_rationale');
        $failure = Fields::optional_object($data, 'failure_reason');
        return new self(
            Fields::required_string($data, 'id', $request_id),
            $status,
            $amount,
            strtoupper(Fields::optional_string($data, 'iso_currency_code')),
            Fields::required_string($data, 'mode', $request_id),
            $transfer_id,
            Fields::optional_string($data, 'authorization_decision'),
            Fields::optional_string($rationale, 'code'),
            Fields::optional_string($failure, 'error_code'),
            Fields::string_map($data, 'metadata'),
            Fields::optional_string($data, 'created'),
            $request_id
        );
    }
}
