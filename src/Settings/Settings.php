<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Settings;

use PayBridge\Plaid\Plaid\PlaidEnvironment;

/**
 * Immutable, validated view of the gateway settings option. All other code
 * reads configuration through this class instead of the raw option array.
 */
final class Settings
{
    public const OPTION = 'woocommerce_paybridge_plaid_settings';
    public const GATEWAY_ID = 'paybridge_plaid';

    public const NETWORKS = array('same-day-ach', 'ach');
    public const ACH_CLASSES = array('web', 'ppd', 'ccd', 'tel');
    public const CONFIRMATION_STATES = array('funds_available', 'settled');

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(): self
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return new self(is_array($stored) ? $stored : array());
    }

    /** @param array<string, mixed> $values */
    public static function from_array(array $values): self
    {
        return new self($values);
    }

    public function enabled(): bool
    {
        return 'yes' === ($this->values['enabled'] ?? 'no');
    }

    public function title(): string
    {
        $title = $this->string('title');
        return '' !== $title ? $title : __('Pay by Bank', 'paybridge-for-plaid');
    }

    public function description(): string
    {
        $description = $this->string('description');
        return '' !== $description ? $description : __('Securely pay directly from your bank account.', 'paybridge-for-plaid');
    }

    public function environment_name(): string
    {
        $environment = $this->string('environment');
        return PlaidEnvironment::is_valid($environment) ? $environment : PlaidEnvironment::SANDBOX;
    }

    public function environment_is_valid(): bool
    {
        return PlaidEnvironment::is_valid($this->string('environment', PlaidEnvironment::SANDBOX));
    }

    public function environment(): PlaidEnvironment
    {
        return PlaidEnvironment::from_string($this->environment_name());
    }

    public function client_id(): string
    {
        return $this->string('client_id');
    }

    /** Server-side only. Never render, log or send this value to the browser. */
    public function secret(): string
    {
        return $this->string('secret');
    }

    /** Optional: only for Plaid Transfer accounts without Plaid Ledger (see ADR-0007). */
    public function funding_account_id(): string
    {
        return $this->string('funding_account_id');
    }

    public function link_customization_name(): string
    {
        return $this->string('link_customization_name');
    }

    public function network(): string
    {
        $network = $this->string('network', 'same-day-ach');
        return in_array($network, self::NETWORKS, true) ? $network : 'same-day-ach';
    }

    public function ach_class(): string
    {
        $class = $this->string('ach_class', 'web');
        return in_array($class, self::ACH_CLASSES, true) ? $class : 'web';
    }

    /** Transfer lifecycle state at which the WooCommerce order is marked paid. */
    public function confirmation_state(): string
    {
        $state = $this->string('confirmation_state', 'funds_available');
        return in_array($state, self::CONFIRMATION_STATES, true) ? $state : 'funds_available';
    }

    public function debug(): bool
    {
        return 'yes' === ($this->values['debug'] ?? 'no');
    }

    public function reconciliation_enabled(): bool
    {
        return 'yes' === ($this->values['reconciliation_enabled'] ?? 'yes');
    }

    public function delete_data_on_uninstall(): bool
    {
        return 'yes' === ($this->values['delete_data_on_uninstall'] ?? 'no');
    }

    public function has_credentials(): bool
    {
        return '' !== $this->client_id() && '' !== $this->secret();
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}
