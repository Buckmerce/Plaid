<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Client;

use PayBridge\Plaid\Plaid\PlaidEnvironment;

interface PlaidClientInterface
{
    /**
     * @param array<string, mixed> $body Request fields excluding credentials.
     * @throws \PayBridge\Plaid\Plaid\Exception\PlaidException
     * @throws \PayBridge\Plaid\Exception\ConfigurationException
     */
    public function post(string $path, array $body): PlaidResponse;

    public function environment(): PlaidEnvironment;
}
