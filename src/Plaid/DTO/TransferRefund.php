<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\DTO;

use Buckmerce\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use Buckmerce\Plaid\Support\Decimal;

/**
 * Normalized refund object of /transfer/refund/create, /transfer/refund/get and
 * /transfer/get transfer.refunds[] (https://plaid.com/docs/api/products/transfer/refunds/).
 */
final class TransferRefund
{
    public const STATUSES = array('pending', 'posted', 'settled', 'cancelled', 'failed', 'returned');

    public function __construct(
        public readonly string $id,
        public readonly string $transfer_id,
        public readonly string $amount,
        public readonly string $status,
        public readonly string $failure_code,
        public readonly string $failure_description,
        public readonly string $created,
        public readonly string $request_id
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data, string $request_id): self
    {
        $amount = Fields::required_string($data, 'amount', $request_id);
        if ('' === Decimal::normalise($amount)) {
            throw new PlaidMalformedResponseException('Plaid refund amount is not a decimal string.', esc_html($request_id));
        }
        $failure = Fields::optional_object($data, 'failure_reason');
        $code = Fields::optional_string($failure, 'failure_code');
        return new self(
            Fields::required_string($data, 'id', $request_id),
            Fields::required_string($data, 'transfer_id', $request_id),
            $amount,
            Fields::enum(Fields::required_string($data, 'status', $request_id), self::STATUSES, 'status', $request_id),
            '' !== $code ? $code : Fields::optional_string($failure, 'ach_return_code'),
            Fields::optional_string($failure, 'description'),
            Fields::optional_string($data, 'created'),
            $request_id
        );
    }
}
