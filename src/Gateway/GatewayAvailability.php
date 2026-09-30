<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Gateway;

use PayBridge\Plaid\Settings\Settings;
use PayBridge\Plaid\Support\Money;

/**
 * Decides whether NEW Pay by Bank payments can be offered. Pure: every input is explicit,
 * which keeps the rules unit-testable and reusable by diagnostics.
 *
 * Accepting new payments and maintaining existing ones are separate concerns: a disabled or
 * incomplete gateway hides Pay by Bank at checkout, but webhooks, event sync, reconciliation,
 * return monitoring and refunds of existing payments keep running (see maintenance_problems()).
 */
final class GatewayAvailability
{
    public const DISABLED = 'disabled';
    public const INVALID_ENVIRONMENT = 'invalid_environment';
    public const MISSING_CREDENTIALS = 'missing_credentials';
    public const MISSING_LINK_CUSTOMIZATION = 'missing_link_customization';
    public const UNSUPPORTED_CURRENCY = 'unsupported_currency';
    public const PRODUCTION_REQUIRES_HTTPS = 'production_requires_https';

    /** @return list<string> Problems independent of the cart/order that block new payments. */
    public static function configuration_problems(Settings $settings): array
    {
        $problems = array();
        if (! $settings->enabled()) {
            $problems[] = self::DISABLED;
        }
        foreach (self::setup_problems($settings) as $problem) {
            $problems[] = $problem;
        }
        return $problems;
    }

    /** @return list<string> Configuration problems other than the on/off switch. */
    public static function setup_problems(Settings $settings): array
    {
        $problems = self::maintenance_problems($settings);
        if ($settings->link_customization_required() && '' === $settings->link_customization_name()) {
            $problems[] = self::MISSING_LINK_CUSTOMIZATION;
        }
        return $problems;
    }

    /** @return list<string> Problems that prevent PayBridge from reading existing payments at Plaid. */
    public static function maintenance_problems(Settings $settings): array
    {
        $problems = array();
        if (! $settings->environment_is_valid()) {
            $problems[] = self::INVALID_ENVIRONMENT;
        }
        if (! $settings->has_credentials()) {
            $problems[] = self::MISSING_CREDENTIALS;
        }
        return $problems;
    }

    /** @return list<string> Empty when the gateway may be offered for a new payment. */
    public static function problems(Settings $settings, string $currency, bool $https): array
    {
        $problems = self::configuration_problems($settings);
        if (Money::SUPPORTED_CURRENCY !== strtoupper($currency)) {
            $problems[] = self::UNSUPPORTED_CURRENCY;
        }
        if ($settings->environment_is_valid() && $settings->environment()->is_production() && ! $https) {
            $problems[] = self::PRODUCTION_REQUIRES_HTTPS;
        }
        return $problems;
    }

    public static function site_uses_https(): bool
    {
        return function_exists('wc_site_is_https') ? wc_site_is_https() : 'https' === wp_parse_url(home_url(), PHP_URL_SCHEME);
    }

    public static function label(string $problem): string
    {
        return match ($problem) {
            self::DISABLED => __('The gateway is disabled, so Pay by Bank is not offered to customers. Existing bank payments are still monitored.', 'paybridge-for-plaid'),
            self::INVALID_ENVIRONMENT => __('The Plaid environment setting is invalid.', 'paybridge-for-plaid'),
            self::MISSING_CREDENTIALS => __('Plaid Client ID and Secret are required.', 'paybridge-for-plaid'),
            self::MISSING_LINK_CUSTOMIZATION => __('Production requires a Plaid Link customization: create one in the Plaid Dashboard with Account Select set to “Enabled for one account” and enter its name.', 'paybridge-for-plaid'),
            self::UNSUPPORTED_CURRENCY => __('The store or order currency is not USD.', 'paybridge-for-plaid'),
            self::PRODUCTION_REQUIRES_HTTPS => __('Production requires the site to use HTTPS.', 'paybridge-for-plaid'),
            default => $problem,
        };
    }
}
