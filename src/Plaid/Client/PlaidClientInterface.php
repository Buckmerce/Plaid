<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Client;

use Buckmerce\Plaid\Plaid\PlaidEnvironment;

interface PlaidClientInterface
{
    /**
     * @param array<string, mixed> $body Request fields excluding credentials.
     * @throws \Buckmerce\Plaid\Plaid\Exception\PlaidException
     * @throws \Buckmerce\Plaid\Exception\ConfigurationException
     */
    public function post(string $path, array $body): PlaidResponse;

    public function environment(): PlaidEnvironment;
}
