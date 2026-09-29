<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use PayBridge\Plaid\Background\EventSyncService;
use PayBridge\Plaid\Background\ReconciliationService;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\REST\WebhookController;
use PayBridge\Plaid\Settings\Settings;

/** WooCommerce → PayBridge diagnostics. Shows configuration booleans and health timestamps, never secrets. */
final class DiagnosticsPage
{
    public const PAGE_SLUG = 'paybridge-plaid-diagnostics';

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    public function register(): void
    {
        add_action('admin_menu', array($this, 'menu'));
    }

    public function menu(): void
    {
        add_submenu_page('woocommerce', __('PayBridge diagnostics', 'paybridge-for-plaid'), __('PayBridge diagnostics', 'paybridge-for-plaid'), 'manage_woocommerce', self::PAGE_SLUG, array($this, 'render'));
    }

    /** @return array<string, string> */
    public static function report(): array
    {
        $settings = Settings::load();
        $yes = __('Yes', 'paybridge-for-plaid');
        $no = __('No', 'paybridge-for-plaid');
        $never = __('Never', 'paybridge-for-plaid');
        $connection = get_option(ConnectionTester::LAST_RESULT_OPTION, array());
        $reconcile_error = get_option(ReconciliationService::LAST_ERROR_OPTION, array());
        $sync_error = get_option(EventSyncService::LAST_ERROR_OPTION, array());
        $store = new TransferEventStore();
        $schema_ok = Installer::schema_is_valid();
        $webhook = get_option(WebhookController::LAST_WEBHOOK_OPTION, array());
        $rejection = get_option(WebhookController::LAST_REJECTION_OPTION, array());
        return array(
            __('Plugin version', 'paybridge-for-plaid') => PAYBRIDGE_PLAID_VERSION,
            __('PHP', 'paybridge-for-plaid') => PHP_VERSION,
            __('WordPress', 'paybridge-for-plaid') => (string) get_bloginfo('version'),
            __('WooCommerce', 'paybridge-for-plaid') => defined('WC_VERSION') ? (string) WC_VERSION : __('Not active', 'paybridge-for-plaid'),
            __('HPOS (custom order tables)', 'paybridge-for-plaid') => class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled() ? $yes : $no,
            __('Checkout Blocks available', 'paybridge-for-plaid') => class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') ? $yes : $no,
            __('HTTPS', 'paybridge-for-plaid') => GatewayAvailability::site_uses_https() ? $yes : $no,
            __('REST API route registered', 'paybridge-for-plaid') => isset(rest_get_server()->get_routes()['/' . RestRoutes::NAMESPACE . '/webhook']) ? $yes : $no,
            __('Action Scheduler', 'paybridge-for-plaid') => function_exists('as_schedule_single_action') ? $yes : $no,
            __('Reconciliation scheduled', 'paybridge-for-plaid') => function_exists('as_has_scheduled_action') && as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP) ? $yes : $no,
            __('Database schema', 'paybridge-for-plaid') => $schema_ok ? sprintf(/* translators: %s: schema version */ __('Valid (version %s)', 'paybridge-for-plaid'), (string) get_option(Installer::OPTION, '')) : __('INVALID', 'paybridge-for-plaid'),
            __('Plaid environment', 'paybridge-for-plaid') => $settings->environment_name(),
            __('Client ID configured', 'paybridge-for-plaid') => '' !== $settings->client_id() ? $yes : $no,
            __('Secret configured', 'paybridge-for-plaid') => '' !== $settings->secret() ? $yes : $no,
            __('Funding Account configured', 'paybridge-for-plaid') => '' !== $settings->funding_account_id() ? $yes : __('No (Plaid Ledger)', 'paybridge-for-plaid'),
            __('Plaid connectivity (last test)', 'paybridge-for-plaid') => is_array($connection) && array() !== $connection ? ConnectionTester::message($connection) . ' ' . (string) ($connection['at'] ?? '') : __('Not tested', 'paybridge-for-plaid'),
            __('Webhook URL', 'paybridge-for-plaid') => rest_url(RestRoutes::NAMESPACE . '/webhook'),
            __('Last verified webhook', 'paybridge-for-plaid') => is_array($webhook) && isset($webhook['at']) ? sprintf('%s %s (%s)', (string) $webhook['at'], (string) ($webhook['code'] ?? ''), (string) ($webhook['outcome'] ?? '')) : $never,
            __('Last rejected webhook', 'paybridge-for-plaid') => is_array($rejection) && isset($rejection['at']) ? sprintf('%s %s (HTTP %d)', (string) $rejection['at'], (string) ($rejection['reason'] ?? ''), (int) ($rejection['status'] ?? 0)) : $never,
            __('Last successful event sync', 'paybridge-for-plaid') => (string) get_option(EventSyncService::LAST_SYNC_OPTION, $never),
            __('Last event sync error', 'paybridge-for-plaid') => is_array($sync_error) && isset($sync_error['at']) ? (string) $sync_error['at'] : $never,
            __('Last reconciliation', 'paybridge-for-plaid') => (string) get_option(ReconciliationService::LAST_RUN_OPTION, $never),
            __('Last reconciliation error', 'paybridge-for-plaid') => is_array($reconcile_error) && isset($reconcile_error['at']) ? (string) $reconcile_error['at'] : $never,
            __('Events waiting for an order match', 'paybridge-for-plaid') => $schema_ok ? (string) $store->count_by_status(TransferEventStore::UNMATCHED) : '-',
            __('Events abandoned after retries', 'paybridge-for-plaid') => $schema_ok ? (string) $store->count_by_status(TransferEventStore::ABANDONED) : '-',
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $report = self::report();
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=' . ConnectionTester::ACTION), ConnectionTester::ACTION);
        $result = get_transient(ConnectionTester::TRANSIENT_PREFIX . get_current_user_id());
        echo '<div class="wrap"><h1>' . esc_html__('PayBridge for Plaid — diagnostics', 'paybridge-for-plaid') . '</h1>';
        if (is_array($result)) {
            echo '<div class="notice ' . esc_attr('connected' === ($result['status'] ?? '') ? 'notice-success' : 'notice-error') . '"><p>' . esc_html(ConnectionTester::message($result)) . '</p></div>';
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="' . esc_url($test_url) . '">' . esc_html__('Test Plaid connection', 'paybridge-for-plaid') . '</a></p>';
        echo '<h2>' . esc_html__('Support report', 'paybridge-for-plaid') . '</h2><p>' . esc_html__('Safe to share: contains no credentials or customer data.', 'paybridge-for-plaid') . '</p>';
        $lines = array();
        foreach ($report as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        echo '<textarea readonly class="large-text code" rows="14">' . esc_textarea(implode("\n", $lines)) . '</textarea></div>';
    }
}
