<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Exception;

use Buckmerce\Plaid\Exception\BuckmerceException;

/** Base class for Plaid API failures. Messages never contain credentials. */
class PlaidException extends BuckmerceException
{
    public function __construct(string $message, private readonly string $request_id = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function request_id(): string
    {
        return $this->request_id;
    }

    /**
     * True when the request may have been processed by Plaid even though no
     * usable response was received. Create operations must not blindly retry.
     */
    public function is_ambiguous(): bool
    {
        return true;
    }

    /** Short, non-sensitive code suitable for logs and order meta. */
    public function safe_code(): string
    {
        return 'plaid_error';
    }
}
