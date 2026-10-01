<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid;

use Buckmerce\Plaid\Exception\ConfigurationException;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Plaid\Client\PlaidClient;
use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Settings\Settings;

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
