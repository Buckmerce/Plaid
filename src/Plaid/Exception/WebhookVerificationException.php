<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Exception;

use Buckmerce\Plaid\Exception\BuckmerceException;

/** A webhook failed cryptographic verification. It must never reach business logic. */
final class WebhookVerificationException extends BuckmerceException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Plaid webhook verification failed: ' . $reason);
    }
}
