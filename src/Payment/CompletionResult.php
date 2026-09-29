<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/** Outcome of the server-side completion check that follows Link onSuccess. */
final class CompletionResult
{
    /** A transfer exists; the customer can see the order confirmation. */
    public const SUBMITTED = 'submitted';
    /** Authorization is not complete yet (e.g. declined for insufficient funds); the customer may retry. */
    public const INCOMPLETE = 'incomplete';
    /** The intent failed; the next attempt creates a new intent. */
    public const FAILED = 'failed';
    /** Plaid could not be reached; nothing was changed. */
    public const UNVERIFIED = 'unverified';

    public function __construct(public readonly string $status, public readonly string $reason_code = '')
    {
    }
}
