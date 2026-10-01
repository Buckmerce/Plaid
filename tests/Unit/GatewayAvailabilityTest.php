<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Gateway\GatewayAvailability;
use Buckmerce\Plaid\Settings\AccountIdentity;
use Buckmerce\Plaid\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class GatewayAvailabilityTest extends TestCase
{
    /** @param array<string, string> $overrides */
    private function settings(array $overrides = array()): Settings
    {
        return Settings::from_array($overrides + array('enabled' => 'yes', 'environment' => 'sandbox', 'client_id' => 'abc123', 'secret' => 's3cr3t', 'link_customization_name' => 'one_account'));
    }

    public function test_configured_sandbox_usd_is_available_without_funding_account(): void
    {
        self::assertSame(array(), GatewayAvailability::problems($this->settings(), 'USD', false));
    }

    public function test_sandbox_also_requires_a_link_customization(): void
    {
        $missing = $this->settings(array('link_customization_name' => ''));
        self::assertSame(array(GatewayAvailability::MISSING_LINK_CUSTOMIZATION), GatewayAvailability::problems($missing, 'USD', false), 'Sandbox uses the same Transfer UI shape as Production: no unspecified default customization.');
        self::assertTrue($missing->link_customization_required());
    }

    public function test_every_requirement_is_enforced(): void
    {
        self::assertContains(GatewayAvailability::DISABLED, GatewayAvailability::problems($this->settings(array('enabled' => 'no')), 'USD', true));
        self::assertContains(GatewayAvailability::MISSING_CREDENTIALS, GatewayAvailability::problems($this->settings(array('client_id' => '')), 'USD', true));
        self::assertContains(GatewayAvailability::MISSING_CREDENTIALS, GatewayAvailability::problems($this->settings(array('secret' => '')), 'USD', true));
        self::assertContains(GatewayAvailability::INVALID_ENVIRONMENT, GatewayAvailability::problems($this->settings(array('environment' => 'development')), 'USD', true));
        self::assertContains(GatewayAvailability::UNSUPPORTED_CURRENCY, GatewayAvailability::problems($this->settings(), 'EUR', true));
    }

    public function test_production_requires_https_and_a_link_customization(): void
    {
        $configured = $this->settings(array('environment' => 'production', 'link_customization_name' => 'one_account'));
        self::assertSame(array(GatewayAvailability::PRODUCTION_REQUIRES_HTTPS), GatewayAvailability::problems($configured, 'USD', false));
        self::assertSame(array(), GatewayAvailability::problems($configured, 'USD', true));

        $missing = $this->settings(array('environment' => 'production', 'link_customization_name' => ''));
        self::assertSame(array(GatewayAvailability::MISSING_LINK_CUSTOMIZATION), GatewayAvailability::problems($missing, 'USD', true), 'Transfer UI needs Account Select “Enabled for one account”: Production fails closed without it.');
        self::assertTrue($missing->link_customization_required());
    }

    public function test_disabled_gateway_blocks_new_payments_but_never_maintenance(): void
    {
        $disabled = $this->settings(array('enabled' => 'no'));
        self::assertContains(GatewayAvailability::DISABLED, GatewayAvailability::configuration_problems($disabled));
        self::assertSame(array(), GatewayAvailability::maintenance_problems($disabled), 'Maintenance of existing payments does not depend on the enabled switch.');
        self::assertTrue($disabled->can_reach_plaid());
        self::assertSame(array(GatewayAvailability::MISSING_CREDENTIALS), GatewayAvailability::maintenance_problems($this->settings(array('enabled' => 'no', 'secret' => ''))));
        self::assertFalse($this->settings(array('secret' => ''))->can_reach_plaid());
    }

    public function test_settings_defaults_and_validation(): void
    {
        $settings = Settings::from_array(array('network' => 'rtp', 'ach_class' => 'ppd', 'confirmation_state' => 'posted', 'environment' => 'bogus'));
        self::assertSame('same-day-ach', $settings->network());
        self::assertSame('web', $settings->ach_class(), 'Transfer UI is an Internet-authorized consumer debit: always WEB, whatever is stored.');
        self::assertSame('web', Settings::from_array(array('ach_class' => 'ccd'))->ach_class());
        self::assertSame('funds_available', $settings->confirmation_state());
        self::assertSame('sandbox', $settings->environment_name());
        self::assertFalse($settings->environment_is_valid());
        self::assertFalse($settings->delete_data_on_uninstall());
        self::assertSame(Settings::DEFAULT_STATEMENT_DESCRIPTOR, $settings->statement_descriptor());
    }

    public function test_statement_descriptor_follows_plaid_ach_rules(): void
    {
        self::assertSame('MY STORE', Settings::normalize_statement_descriptor('my store'));
        self::assertSame('ACMESHOP', Settings::normalize_statement_descriptor('Acme-Shop!'));
        self::assertSame('ORDER PAYM', Settings::normalize_statement_descriptor('Order   payment 123'), 'ACH shows at most 10 characters.');
        self::assertSame('', Settings::normalize_statement_descriptor('ÜÖ€<>'));
        self::assertSame('PAYMENT', Settings::from_array(array('statement_descriptor' => '***'))->statement_descriptor());
        foreach (array('x', 'Shop 123 Payment', "tab\tname", '<b>BOLD</b>') as $input) {
            $value = Settings::normalize_statement_descriptor($input);
            self::assertMatchesRegularExpression('/^[A-Z0-9 ]{0,10}$/', $value);
            self::assertSame($value, trim($value));
        }
    }

    public function test_account_identity_is_a_stable_non_secret_fingerprint_of_the_client_id(): void
    {
        $a = AccountIdentity::fingerprint('5f1a2b3c4d');
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $a);
        self::assertSame($a, AccountIdentity::fingerprint(' 5F1A2B3C4D '));
        self::assertNotSame($a, AccountIdentity::fingerprint('5f1a2b3c4e'));
        self::assertSame('', AccountIdentity::fingerprint(''));
        self::assertStringNotContainsString('5f1a2b3c4d', $a);
        $rotated = Settings::from_array(array('client_id' => '5f1a2b3c4d', 'secret' => 'old'))->account_fingerprint();
        self::assertSame($rotated, Settings::from_array(array('client_id' => '5f1a2b3c4d', 'secret' => 'new'))->account_fingerprint(), 'Secret rotation keeps the account identity.');
    }
}
