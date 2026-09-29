<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Support;

final class Requirements
{
    public function is_met(): bool
    {
        return version_compare(PHP_VERSION, '8.1', '>=')
            && version_compare((string) get_bloginfo('version'), '6.6', '>=')
            && defined('WC_VERSION')
            && version_compare((string) WC_VERSION, '8.5', '>=')
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
                echo '<div class="notice notice-error"><p>' . esc_html__('PayBridge for Plaid requires PHP 8.1+ with the OpenSSL extension, WordPress 6.6+, and WooCommerce 8.5+.', 'paybridge-for-plaid') . '</p></div>';
            }
        );
    }
}
