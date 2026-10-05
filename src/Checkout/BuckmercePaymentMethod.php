<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Checkout;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Buckmerce\Plaid\Gateway\GatewayAvailability;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Checkout Blocks integration. Presentation only: payment initiation runs in
 * the gateway's process_payment(), shared with Classic Checkout.
 */
final class BuckmercePaymentMethod extends AbstractPaymentMethodType
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
        $handle = 'buckmerce-plaid-blocks';
        // The script holds no translatable text: get_payment_method_data() sends the translated title and description.
        wp_register_script($handle, BUCKMERCE_PLAID_URL . 'assets/blocks.js', array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'), BUCKMERCE_PLAID_VERSION, true);
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
            'icon' => BUCKMERCE_PLAID_URL . 'assets/buckmerce-mark.svg',
            'supports' => array('products'),
            'available' => array() === GatewayAvailability::problems($settings, get_woocommerce_currency(), GatewayAvailability::site_uses_https()),
        );
    }
}
