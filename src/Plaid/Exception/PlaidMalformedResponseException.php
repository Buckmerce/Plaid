<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Exception;

/** Plaid responded, but the body is not the documented schema. */
final class PlaidMalformedResponseException extends PlaidException
{
    public function safe_code(): string
    {
        return 'plaid_malformed_response';
    }
}
