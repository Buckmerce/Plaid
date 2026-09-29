<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Exception;

/** Transport failure or timeout: Plaid may or may not have processed the request. */
final class PlaidNetworkException extends PlaidException
{
    public function safe_code(): string
    {
        return 'plaid_network_error';
    }
}
