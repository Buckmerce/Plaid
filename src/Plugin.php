<?php

declare(strict_types=1);

namespace Buckmerce\Plaid;

use Buckmerce\Plaid\Admin\AdminNotices;
use Buckmerce\Plaid\Admin\ConnectionTester;
use Buckmerce\Plaid\Admin\DiagnosticsPage;
use Buckmerce\Plaid\Admin\OrderListColumn;
use Buckmerce\Plaid\Admin\OrderMetaBox;
use Buckmerce\Plaid\Admin\SiteHealth;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Checkout\BuckmercePaymentMethod;
use Buckmerce\Plaid\Checkout\PaymentPage;
use Buckmerce\Plaid\Gateway\BuckmerceGateway;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\REST\RestRoutes;
use Buckmerce\Plaid\Refund\WooRefundContext;
use Buckmerce\Plaid\Settings\AccountChangeGuard;

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
                        echo '<div class="notice notice-error"><p>' . esc_html__('Buckmerce for Plaid could not create or verify its database tables. Pay by Bank stays disabled until this is fixed; try deactivating and reactivating the plugin.', 'buckmerce-for-plaid') . '</p></div>';
                    }
                });
                return;
            }
        }

        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = BuckmerceGateway::class;
            return $gateways;
        });
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_blocks'));
        add_filter('plugin_action_links_' . plugin_basename(BUCKMERCE_PLAID_FILE), array($this, 'action_links'));
        add_action('admin_enqueue_scripts', array(BuckmerceGateway::class, 'enqueue_admin_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_order_styles'));
        add_action('admin_init', array($this, 'privacy_policy'));

        ( new RestRoutes() )->register();
        ( new PaymentPage() )->register();
        ( new Scheduler(new Container()) )->register();
        ( new ConnectionTester() )->register();
        ( new OrderMetaBox() )->register();
        ( new DiagnosticsPage() )->register();
        ( new SiteHealth() )->register();
        ( new AdminNotices() )->register();
        ( new OrderListColumn() )->register();
        ( new AccountChangeGuard() )->register();
        WooRefundContext::register();
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('buckmerce-plaid', CLI\Command::class);
        }
    }

    /** Badge and panel styles on the WooCommerce order list and order edit screens (HPOS and legacy). */
    public function enqueue_order_styles(string $hook_suffix): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = null === $screen ? '' : (string) $screen->id;
        if (! in_array($hook_suffix, array('woocommerce_page_wc-orders', 'woocommerce_page_' . DiagnosticsPage::PAGE_SLUG), true) && ! in_array($screen_id, array('edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders'), true)) {
            return;
        }
        wp_enqueue_style('buckmerce-plaid-admin', BUCKMERCE_PLAID_URL . 'assets/admin-settings.css', array(), BUCKMERCE_PLAID_VERSION);
    }

    /** @param object $registry Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry */
    public function register_blocks($registry): void
    {
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') && method_exists($registry, 'register')) {
            $registry->register(new BuckmercePaymentMethod());
        }
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function action_links(array $links): array
    {
        if (current_user_can('manage_woocommerce')) {
            $links['bmfp_settings'] = '<a href="' . esc_url(BuckmerceGateway::settings_url()) . '">' . esc_html__('Settings', 'buckmerce-for-plaid') . '</a>';
            $links['bmfp_diagnostics'] = '<a href="' . esc_url(DiagnosticsPage::url()) . '">' . esc_html__('Diagnostics', 'buckmerce-for-plaid') . '</a>';
        }
        return $links;
    }

    public function privacy_policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Buckmerce for Plaid', 'buckmerce-for-plaid'),
            '<p>' . esc_html__('When a customer chooses Pay by Bank, this store sends the order amount, a short order description, the customer’s billing name and email address, and internal order references to Plaid Inc. to create the payment. The customer connects their bank and authorizes the payment inside Plaid’s interface; bank login credentials are never shared with this store. Plaid processes this data under its own terms and privacy policy (https://plaid.com/legal/). The store keeps Plaid payment identifiers and statuses on the order for accounting and dispute handling.', 'buckmerce-for-plaid') . '</p>'
        );
    }
}
