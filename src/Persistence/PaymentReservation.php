<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

final class PaymentReservation
{
    public const ACQUIRED = 'acquired';
    public const BUSY = 'busy';
    public const ACTIVE_INTENT = 'active_intent';
    public const ERROR = 'error';

    public function __construct(public readonly string $status, public readonly string $owner_token = '')
    {
    }

    public function acquired(): bool
    {
        return self::ACQUIRED === $this->status;
    }
}
