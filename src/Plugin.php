<?php

declare(strict_types=1);

namespace PayBridge\Plaid;

use PayBridge\Plaid\Admin\AdminNotices;
use PayBridge\Plaid\Admin\ConnectionTester;
use PayBridge\Plaid\Admin\DiagnosticsPage;
use PayBridge\Plaid\Admin\OrderMetaBox;
use PayBridge\Plaid\Admin\SiteHealth;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Checkout\PayBridgePaymentMethod;
use PayBridge\Plaid\Checkout\PaymentPage;
use PayBridge\Plaid\Gateway\PayBridgeGateway;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\REST\RestRoutes;

final class Plugin
{
    public function register(): void
    {
        if (! Installer::schema_is_current()) {
            try {
                Installer::install();
            } catch (\Throwable $exception) {
                // Fail closed: without verified idempotency tables no payment may start.
                add_action('admin_notices', static function (): void {
                    if (current_user_can('manage_woocommerce')) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('PayBridge for Plaid could not create or verify its database tables. Pay by Bank stays disabled until this is fixed; try deactivating and reactivating the plugin.', 'paybridge-for-plaid') . '</p></div>';
                    }
                });
                return;
            }
        }

        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = PayBridgeGateway::class;
            return $gateways;
        });
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_blocks'));
        add_filter('plugin_action_links_' . plugin_basename(PAYBRIDGE_PLAID_FILE), array($this, 'action_links'));
        add_action('admin_enqueue_scripts', array(PayBridgeGateway::class, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'privacy_policy'));

        ( new RestRoutes() )->register();
        ( new PaymentPage() )->register();
        ( new Scheduler(new Container()) )->register();
        ( new ConnectionTester() )->register();
        ( new OrderMetaBox() )->register();
        ( new DiagnosticsPage() )->register();
        ( new SiteHealth() )->register();
        ( new AdminNotices() )->register();
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('paybridge-plaid', CLI\Command::class);
        }
    }

    /** @param object $registry Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry */
    public function register_blocks($registry): void
    {
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') && method_exists($registry, 'register')) {
            $registry->register(new PayBridgePaymentMethod());
        }
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function action_links(array $links): array
    {
        if (current_user_can('manage_woocommerce')) {
            $links['pbfp_settings'] = '<a href="' . esc_url(PayBridgeGateway::settings_url()) . '">' . esc_html__('Settings', 'paybridge-for-plaid') . '</a>';
            $links['pbfp_diagnostics'] = '<a href="' . esc_url(DiagnosticsPage::url()) . '">' . esc_html__('Diagnostics', 'paybridge-for-plaid') . '</a>';
        }
        return $links;
    }

    public function privacy_policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('PayBridge for Plaid', 'paybridge-for-plaid'),
            '<p>' . esc_html__('When a customer chooses Pay by Bank, this store sends the order amount, a short order description, the customer’s billing name and email address, and internal order references to Plaid Inc. to create the payment. The customer connects their bank and authorizes the payment inside Plaid’s interface; bank login credentials are never shared with this store. Plaid processes this data under its own terms and privacy policy (https://plaid.com/legal/). The store keeps Plaid payment identifiers and statuses on the order for accounting and dispute handling.', 'paybridge-for-plaid') . '</p>'
        );
    }
}
