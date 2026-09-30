<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\DTO;

use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;

/**
 * One /transfer/event/sync transfer_events[] entry. event_id is kept as a digit string
 * (unsigned 64-bit). Refund events carry the ORIGINAL transfer_id plus a non-null
 * refund_id and a "refund." event_type prefix (docs/api/transfer/refunds.md#refund-events);
 * they must never be read as events of the payment itself.
 */
final class TransferEvent
{
    /** Transfer lifecycle event types that drive payment state. */
    public const LIFECYCLE_TYPES = array('pending', 'posted', 'settled', 'funds_available', 'failed', 'cancelled', 'returned');

    /** Refund lifecycle statuses (event_type without the "refund." prefix). */
    public const REFUND_TYPES = array('pending', 'posted', 'settled', 'failed', 'cancelled', 'returned');

    public function __construct(
        public readonly string $event_id,
        public readonly string $event_type,
        public readonly string $transfer_id,
        public readonly string $transfer_type,
        public readonly string $transfer_amount,
        public readonly string $intent_id,
        public readonly string $failure_code,
        public readonly string $ach_return_code,
        public readonly string $failure_description,
        public readonly string $timestamp,
        public readonly string $refund_id = '',
        public readonly string $event_amount = ''
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data, string $request_id): self
    {
        $raw_id = $data['event_id'] ?? null;
        $event_id = is_int($raw_id) ? (string) $raw_id : (is_string($raw_id) ? $raw_id : '');
        if (! preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $event_id)) {
            throw new PlaidMalformedResponseException('Plaid transfer event has an invalid event_id.', esc_html($request_id));
        }
        $failure = Fields::optional_object($data, 'failure_reason');
        return new self(
            $event_id,
            Fields::required_string($data, 'event_type', $request_id),
            Fields::optional_string($data, 'transfer_id'),
            Fields::optional_string($data, 'transfer_type'),
            Fields::optional_string($data, 'transfer_amount'),
            Fields::optional_string($data, 'intent_id'),
            Fields::optional_string($failure, 'failure_code'),
            Fields::optional_string($failure, 'ach_return_code'),
            Fields::optional_string($failure, 'description'),
            Fields::optional_string($data, 'timestamp'),
            Fields::optional_string($data, 'refund_id'),
            Fields::optional_string($data, 'event_amount')
        );
    }

    /** An event of the payment (debit) itself. Refund events are excluded even if a type looked familiar. */
    public function is_lifecycle_event(): bool
    {
        return '' === $this->refund_id && in_array($this->event_type, self::LIFECYCLE_TYPES, true) && '' !== $this->transfer_id;
    }

    public function is_refund_event(): bool
    {
        return '' !== $this->refund_id && '' !== $this->transfer_id && '' !== $this->refund_status();
    }

    /** Events PayBridge processes; everything else (sweeps, adjustments, guarantees) is only recorded. */
    public function is_processable(): bool
    {
        return $this->is_lifecycle_event() || $this->is_refund_event();
    }

    /** Refund status carried by a refund event ("refund.settled" → "settled"), or ''. */
    public function refund_status(): string
    {
        if ('' === $this->refund_id) {
            return '';
        }
        $status = str_starts_with($this->event_type, 'refund.') ? substr($this->event_type, 7) : $this->event_type;
        return in_array($status, self::REFUND_TYPES, true) ? $status : '';
    }

    /** Return reason code such as R01 when available (ach_return_code is deprecated in favour of failure_code). */
    public function return_code(): string
    {
        return '' !== $this->failure_code ? $this->failure_code : $this->ach_return_code;
    }
}
