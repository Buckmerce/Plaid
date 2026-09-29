<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class GatewayAvailabilityTest extends TestCase
{
    /** @param array<string, string> $overrides */
    private function settings(array $overrides = array()): Settings
    {
        return Settings::from_array($overrides + array('enabled' => 'yes', 'environment' => 'sandbox', 'client_id' => 'abc123', 'secret' => 's3cr3t'));
    }

    public function test_configured_sandbox_usd_is_available_without_funding_account(): void
    {
        self::assertSame(array(), GatewayAvailability::problems($this->settings(), 'USD', false));
    }

    public function test_every_requirement_is_enforced(): void
    {
        self::assertContains(GatewayAvailability::DISABLED, GatewayAvailability::problems($this->settings(array('enabled' => 'no')), 'USD', true));
        self::assertContains(GatewayAvailability::MISSING_CREDENTIALS, GatewayAvailability::problems($this->settings(array('client_id' => '')), 'USD', true));
        self::assertContains(GatewayAvailability::MISSING_CREDENTIALS, GatewayAvailability::problems($this->settings(array('secret' => '')), 'USD', true));
        self::assertContains(GatewayAvailability::INVALID_ENVIRONMENT, GatewayAvailability::problems($this->settings(array('environment' => 'development')), 'USD', true));
        self::assertContains(GatewayAvailability::UNSUPPORTED_CURRENCY, GatewayAvailability::problems($this->settings(), 'EUR', true));
    }

    public function test_production_requires_https(): void
    {
        $production = $this->settings(array('environment' => 'production'));
        self::assertSame(array(GatewayAvailability::PRODUCTION_REQUIRES_HTTPS), GatewayAvailability::problems($production, 'USD', false));
        self::assertSame(array(), GatewayAvailability::problems($production, 'USD', true));
    }

    public function test_settings_defaults_and_validation(): void
    {
        $settings = Settings::from_array(array('network' => 'rtp', 'ach_class' => 'xyz', 'confirmation_state' => 'posted', 'environment' => 'bogus'));
        self::assertSame('same-day-ach', $settings->network());
        self::assertSame('web', $settings->ach_class());
        self::assertSame('funds_available', $settings->confirmation_state());
        self::assertSame('sandbox', $settings->environment_name());
        self::assertFalse($settings->environment_is_valid());
        self::assertTrue($settings->reconciliation_enabled());
        self::assertFalse($settings->delete_data_on_uninstall());
    }
}
