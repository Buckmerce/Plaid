<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\DTO;

use Buckmerce\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use Buckmerce\Plaid\Support\Decimal;

/** Normalized /transfer/get transfer object (fields Buckmerce relies on). */
final class Transfer
{
    public const STATUSES = array('pending', 'posted', 'settled', 'funds_available', 'cancelled', 'failed', 'returned');

    /**
     * @param array<string, string> $metadata
     * @param list<TransferRefund>  $refunds
     */
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
        public readonly string $request_id,
        public readonly string $created = '',
        public readonly string $standard_return_window = '',
        public readonly string $unauthorized_return_window = '',
        public readonly string $expected_funds_available_date = '',
        public readonly bool $cancellable = false,
        public readonly array $refunds = array()
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data, string $request_id): self
    {
        $amount = Fields::required_string($data, 'amount', $request_id);
        if ('' === Decimal::normalise($amount)) {
            throw new PlaidMalformedResponseException('Plaid transfer amount is not a decimal string.', esc_html($request_id));
        }
        $failure = Fields::optional_object($data, 'failure_reason');
        $refunds = array();
        $raw_refunds = $data['refunds'] ?? array();
        foreach (is_array($raw_refunds) ? $raw_refunds : array() as $refund) {
            if (is_array($refund)) {
                $refunds[] = TransferRefund::from_array($refund, $request_id);
            }
        }
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
            $request_id,
            Fields::optional_string($data, 'created'),
            Fields::optional_date($data, 'standard_return_window'),
            Fields::optional_date($data, 'unauthorized_return_window'),
            Fields::optional_date($data, 'expected_funds_available_date'),
            true === ($data['cancellable'] ?? false),
            $refunds
        );
    }

    /** Return reason code such as R01 (ach_return_code is deprecated in favour of failure_code). */
    public function return_code(): string
    {
        return '' !== $this->failure_code ? $this->failure_code : $this->ach_return_code;
    }
}
