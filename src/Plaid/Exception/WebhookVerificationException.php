<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Exception;

use PayBridge\Plaid\Exception\PayBridgeException;

/** A webhook failed cryptographic verification. It must never reach business logic. */
final class WebhookVerificationException extends PayBridgeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Plaid webhook verification failed: ' . $reason);
    }
}
