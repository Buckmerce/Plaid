<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Checkout;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Settings\Settings;

/**
 * Checkout Blocks integration. Presentation only: payment initiation runs in
 * the gateway's process_payment(), shared with Classic Checkout.
 */
final class PayBridgePaymentMethod extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Settings::GATEWAY_ID;

    public function initialize(): void
    {
        $settings = get_option(Settings::OPTION, array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active(): bool
    {
        return array() === GatewayAvailability::configuration_problems(Settings::load());
    }

    /** @return list<string> */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'paybridge-plaid-blocks';
        wp_register_script($handle, PAYBRIDGE_PLAID_URL . 'assets/build/blocks.js', array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'), PAYBRIDGE_PLAID_VERSION, true);
        wp_set_script_translations($handle, 'paybridge-for-plaid', PAYBRIDGE_PLAID_DIR . 'languages');
        return array($handle);
    }

    /** @return array<string, mixed> */
    public function get_payment_method_data(): array
    {
        // Always read the current option: the registry may have initialised this instance earlier in the request.
        $settings = Settings::load();
        return array(
            'title' => $settings->title(),
            'description' => $settings->description(),
            'icon' => PAYBRIDGE_PLAID_URL . 'assets/images/paybridge-mark.svg',
            'supports' => array('products'),
            'available' => array() === GatewayAvailability::problems($settings, get_woocommerce_currency(), GatewayAvailability::site_uses_https()),
        );
    }
}
