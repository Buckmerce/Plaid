<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Support;

final class Requirements
{
    /**
     * WooCommerce 8.7.0 is the first release whose HPOS data store persists refund properties
     * changed after the refund was created (WooCommerce PR #44214). Earlier versions drop
     * WC_Order_Refund::set_refunded_payment(true) under HPOS, so a successful Plaid refund would
     * not be recorded as refunded through the gateway (ADR-0022).
     */
    public const MIN_WOOCOMMERCE = '8.7';

    public function is_met(): bool
    {
        return version_compare(PHP_VERSION, '8.1', '>=')
            && version_compare((string) get_bloginfo('version'), '6.6', '>=')
            && defined('WC_VERSION')
            && version_compare((string) WC_VERSION, self::MIN_WOOCOMMERCE, '>=')
            && function_exists('openssl_verify');
    }

    public function register_notice(): void
    {
        add_action(
            'admin_notices',
            static function (): void {
                if (! current_user_can('activate_plugins')) {
                    return;
                }
                echo '<div class="notice notice-error"><p>' . esc_html__('PayBridge for Plaid requires PHP 8.1+ with the OpenSSL extension, WordPress 6.6+, and WooCommerce 8.7+.', 'paybridge-for-plaid') . '</p></div>';
            }
        );
    }
}
