<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid;

use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Plaid\Client\PlaidClient;
use PayBridge\Plaid\Plaid\Client\PlaidClientInterface;
use PayBridge\Plaid\Settings\Settings;

final class PlaidClientFactory
{
    /** @throws ConfigurationException */
    public function create(Settings $settings): PlaidClientInterface
    {
        if (! $settings->environment_is_valid()) {
            throw new ConfigurationException('The Plaid environment setting is invalid.');
        }
        if (! $settings->has_credentials()) {
            throw new ConfigurationException('Plaid Client ID and Secret are required.');
        }
        return new PlaidClient($settings->environment(), $settings->client_id(), $settings->secret(), null, new Logger());
    }
}
