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
    public const CONFIRMATION_STATES = array('funds_available', 'settled');

    /**
     * Plaid Transfer UI captures a consumer's authorization over the Internet, which is the
     * Nacha WEB entry class (docs/api/transfer/using-transfer-ui.md). No other class is a
     * valid description of a WooCommerce web checkout, so it is not configurable (ADR-0013).
     */
    public const ACH_CLASS = 'web';

    /** Plaid recommends a purpose word such as PAYMENT; ACH shows at most 10 characters. */
    public const DEFAULT_STATEMENT_DESCRIPTOR = 'PAYMENT';
    public const STATEMENT_DESCRIPTOR_MAX = 10;

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

    /** Whether the merchant accepts NEW Pay by Bank payments. Existing payments are maintained regardless. */
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

    public function is_production(): bool
    {
        return PlaidEnvironment::PRODUCTION === $this->environment_name();
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

    /** Non-secret identity of the configured Plaid account (ADR-0015). */
    public function account_fingerprint(): string
    {
        return AccountIdentity::fingerprint($this->client_id());
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

    /**
     * Transfer UI requires a Link customization with Account Select "Enabled for one
     * account". Production fails closed without one; Sandbox may use Plaid's default.
     */
    public function link_customization_required(): bool
    {
        return $this->is_production();
    }

    public function network(): string
    {
        $network = $this->string('network', 'same-day-ach');
        return in_array($network, self::NETWORKS, true) ? $network : 'same-day-ach';
    }

    public function ach_class(): string
    {
        return self::ACH_CLASS;
    }

    /** Text shown on the customer's bank statement after the company name Plaid has on file. */
    public function statement_descriptor(): string
    {
        $descriptor = self::normalize_statement_descriptor($this->string('statement_descriptor', self::DEFAULT_STATEMENT_DESCRIPTOR));
        return '' === $descriptor ? self::DEFAULT_STATEMENT_DESCRIPTOR : $descriptor;
    }

    /**
     * Banks show ACH descriptions as upper-case ASCII without punctuation, 10 characters at
     * most (docs/api/transfer/creating-transfers.md). Returns '' when nothing usable remains.
     */
    public static function normalize_statement_descriptor(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9 ]/', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return trim(substr($value, 0, self::STATEMENT_DESCRIPTOR_MAX));
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

    public function delete_data_on_uninstall(): bool
    {
        return 'yes' === ($this->values['delete_data_on_uninstall'] ?? 'no');
    }

    public function has_credentials(): bool
    {
        return '' !== $this->client_id() && '' !== $this->secret();
    }

    /**
     * Whether PayBridge can read existing payments from Plaid: background maintenance
     * (event sync, reconciliation, refunds) depends on this, never on enabled().
     */
    public function can_reach_plaid(): bool
    {
        return $this->environment_is_valid() && $this->has_credentials();
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}
