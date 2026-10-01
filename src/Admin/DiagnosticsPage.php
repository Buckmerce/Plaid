<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use PayBridge\Plaid\Background\EventSyncService;
use PayBridge\Plaid\Background\ReconciliationService;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Persistence\EventCursor;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Persistence\PaymentEpoch;
use PayBridge\Plaid\Persistence\PaymentLockStatus;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Persistence\RefundStore;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\REST\WebhookController;
use PayBridge\Plaid\Settings\Settings;

/**
 * WooCommerce → PayBridge diagnostics. Configuration booleans, health timestamps and
 * operational counts from indexed PayBridge tables — never secrets, never customer data,
 * never a Plaid call on page view.
 */
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
        $scope = $settings->account_scope();
        $sync = EventSyncService::health($scope);
        $sync_error = $sync['last_error'];
        $epoch = PaymentEpoch::get($scope);
        $link_error = get_option(PaymentAttemptService::LAST_LINK_ERROR_OPTION, array());
        $schema_ok = Installer::schema_is_valid();
        $webhook = get_option(WebhookController::LAST_WEBHOOK_OPTION, array());
        $rejection = get_option(WebhookController::LAST_REJECTION_OPTION, array());
        $status = ConfigurationStatus::evaluate($settings);
        $environment = $settings->environment_name();
        $report = array(
            __('Status', 'paybridge-for-plaid') => $status['label'],
            __('Plugin version', 'paybridge-for-plaid') => PAYBRIDGE_PLAID_VERSION,
            __('PHP', 'paybridge-for-plaid') => PHP_VERSION,
            __('WordPress', 'paybridge-for-plaid') => (string) get_bloginfo('version'),
            __('WooCommerce', 'paybridge-for-plaid') => defined('WC_VERSION') ? (string) WC_VERSION : __('Not active', 'paybridge-for-plaid'),
            __('Database server', 'paybridge-for-plaid') => self::database_version(),
            __('HPOS (custom order tables)', 'paybridge-for-plaid') => class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled() ? $yes : $no,
            __('Checkout Blocks available', 'paybridge-for-plaid') => class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') ? $yes : $no,
            __('HTTPS', 'paybridge-for-plaid') => GatewayAvailability::site_uses_https() ? $yes : $no,
            __('REST API route registered', 'paybridge-for-plaid') => isset(rest_get_server()->get_routes()['/' . RestRoutes::NAMESPACE . '/webhook']) ? $yes : $no,
            __('Action Scheduler', 'paybridge-for-plaid') => function_exists('as_schedule_single_action') ? $yes : $no,
            __('WP-Cron disabled (DISABLE_WP_CRON)', 'paybridge-for-plaid') => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? $yes : $no,
            __('Background maintenance active', 'paybridge-for-plaid') => Scheduler::maintenance_active($settings) ? $yes : $no,
            __('Reconciliation scheduled', 'paybridge-for-plaid') => function_exists('as_has_scheduled_action') && as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP) ? $yes : $no,
            __('Overdue PayBridge jobs (>30 min)', 'paybridge-for-plaid') => (string) ConfigurationStatus::stalled_actions(),
            __('Failed PayBridge jobs (7 days)', 'paybridge-for-plaid') => (string) ConfigurationStatus::failed_actions(),
            __('Database schema', 'paybridge-for-plaid') => $schema_ok ? sprintf(/* translators: %s: schema version */ __('Valid (version %s)', 'paybridge-for-plaid'), (string) get_option(Installer::OPTION, '')) : __('INVALID', 'paybridge-for-plaid'),
            __('Gateway enabled (new payments)', 'paybridge-for-plaid') => $settings->enabled() ? $yes : $no,
            __('Plaid environment', 'paybridge-for-plaid') => $environment,
            __('Client ID configured', 'paybridge-for-plaid') => '' !== $settings->client_id() ? $yes : $no,
            __('Secret configured', 'paybridge-for-plaid') => '' !== $settings->secret() ? $yes : $no,
            __('Plaid account fingerprint', 'paybridge-for-plaid') => '' === $settings->account_fingerprint() ? '-' : $settings->account_fingerprint(),
            __('Funding Account configured', 'paybridge-for-plaid') => '' !== $settings->funding_account_id() ? $yes : __('No (Plaid Ledger)', 'paybridge-for-plaid'),
            __('Link customization configured', 'paybridge-for-plaid') => '' !== $settings->link_customization_name() ? __('PASS', 'paybridge-for-plaid') : __('FAIL (required by Transfer UI in Sandbox and Production)', 'paybridge-for-plaid'),
            __('Last Link session error', 'paybridge-for-plaid') => is_array($link_error) && isset($link_error['at']) ? sprintf('%s %s', (string) $link_error['at'], (string) ($link_error['code'] ?? '')) : $never,
            __('Bank statement description', 'paybridge-for-plaid') => $settings->statement_descriptor(),
            __('ACH class', 'paybridge-for-plaid') => strtoupper($settings->ach_class()),
            __('Plaid connectivity (last test)', 'paybridge-for-plaid') => is_array($connection) && array() !== $connection ? ConnectionTester::message($connection) . ' ' . (string) ($connection['at'] ?? '') : __('Not tested', 'paybridge-for-plaid'),
            __('Webhook URL', 'paybridge-for-plaid') => rest_url(RestRoutes::NAMESPACE . '/webhook'),
            __('Last verified webhook', 'paybridge-for-plaid') => is_array($webhook) && isset($webhook['at']) ? sprintf('%s %s (%s)', (string) $webhook['at'], (string) ($webhook['code'] ?? ''), (string) ($webhook['outcome'] ?? '')) : $never,
            __('Last rejected webhook', 'paybridge-for-plaid') => is_array($rejection) && isset($rejection['at']) ? sprintf('%s %s (HTTP %d)', (string) $rejection['at'], (string) ($rejection['reason'] ?? ''), (int) ($rejection['status'] ?? 0)) : $never,
            __('Event stream (environment/account)', 'paybridge-for-plaid') => $scope->is_valid() ? $scope->environment . ' / ' . $scope->account_fp : '-',
            __('Event stream cursor (last stored event ID)', 'paybridge-for-plaid') => $scope->is_valid() ? ( new EventCursor() )->get($scope) : '-',
            __('First payment with this account (epoch)', 'paybridge-for-plaid') => null === $epoch ? $never : gmdate('c', $epoch),
            __('Last successful event sync', 'paybridge-for-plaid') => '' === $sync['last_sync'] ? $never : $sync['last_sync'],
            __('Last event sync error', 'paybridge-for-plaid') => isset($sync_error['at']) ? sprintf('%s %s (%s)', $sync_error['at'], $sync_error['code'] ?? '', $sync_error['category'] ?? '') : $never,
            __('Consecutive event sync failures', 'paybridge-for-plaid') => (string) $sync['failures'],
            __('Last reconciliation', 'paybridge-for-plaid') => (string) get_option(ReconciliationService::LAST_RUN_OPTION, $never),
            __('Last reconciliation error', 'paybridge-for-plaid') => is_array($reconcile_error) && isset($reconcile_error['at']) ? sprintf('%s (%s)', (string) $reconcile_error['at'], (string) ($reconcile_error['category'] ?? '')) : $never,
        );
        if (! $schema_ok) {
            return $report;
        }
        $locks = new PaymentLockStore();
        $events = new TransferEventStore();
        $refund_counts = ( new RefundStore() )->counts($scope);
        $event_counts = $events->counts($scope);
        $states = $locks->count_by_state($environment);
        $monitored = $locks->monitored($environment, $settings->account_fingerprint());
        $sum = static fn (array $counts, array $keys): int => array_sum(array_intersect_key($counts, array_flip($keys)));
        return $report + array(
            __('Monitored bank payments', 'paybridge-for-plaid') => (string) $monitored['count'],
            __('Oldest monitored payment (attempt created)', 'paybridge-for-plaid') => '' === $monitored['oldest'] ? '-' : $monitored['oldest'] . ' UTC',
            __('Payments in flight (pending/posted)', 'paybridge-for-plaid') => (string) $sum($states, array('transfer_created', 'pending', 'posted')),
            __('Payments awaiting authorization', 'paybridge-for-plaid') => (string) $sum($states, array('intent_created', 'intent_pending')),
            __('Payments in manual review', 'paybridge-for-plaid') => (string) ($states['manual_review'] ?? 0),
            __('Returned payments', 'paybridge-for-plaid') => (string) ($states['returned'] ?? 0),
            __('Intent creations with unknown outcome', 'paybridge-for-plaid') => (string) $locks->count_by_lock_status(PaymentLockStatus::UNCERTAIN),
            __('Payments of another Plaid account', 'paybridge-for-plaid') => (string) $locks->count_other_account($environment, $settings->account_fingerprint()),
            __('Refunds pending (in flight/unconfirmed)', 'paybridge-for-plaid') => (string) $sum($refund_counts, array('creating', 'uncertain', 'pending', 'posted')),
            __('Refunds settled', 'paybridge-for-plaid') => (string) ($refund_counts['settled'] ?? 0),
            __('Refunds failed or returned', 'paybridge-for-plaid') => (string) $sum($refund_counts, array('failed', 'returned')),
            __('Event backlog (waiting to be processed)', 'paybridge-for-plaid') => (string) $sum($event_counts, array(TransferEventStore::RECEIVED, TransferEventStore::RETRY, TransferEventStore::PROCESSING)),
            __('Events waiting for an order match', 'paybridge-for-plaid') => (string) ($event_counts[TransferEventStore::UNMATCHED] ?? 0),
            __('Events abandoned after retries', 'paybridge-for-plaid') => (string) ($event_counts[TransferEventStore::ABANDONED] ?? 0),
            __('Events of previous accounts or schema 2 (audit only)', 'paybridge-for-plaid') => (string) $events->count_outside($scope),
        );
    }

    private static function database_version(): string
    {
        global $wpdb;
        $version = is_object($wpdb) && method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';
        return '' === $version ? '-' : substr(preg_replace('/[^A-Za-z0-9 .\-_]/', '', $version) ?? '', 0, 60);
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = Settings::load();
        $status = ConfigurationStatus::evaluate($settings);
        $report = self::report();
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=' . ConnectionTester::ACTION), ConnectionTester::ACTION);
        $result = get_transient(ConnectionTester::TRANSIENT_PREFIX . get_current_user_id());
        echo '<div class="wrap"><h1>' . esc_html__('PayBridge for Plaid — diagnostics', 'paybridge-for-plaid') . ' <span class="pbfp-badge pbfp-badge--' . esc_attr($settings->is_production() ? 'production' : 'sandbox') . '">' . esc_html($settings->is_production() ? __('Production', 'paybridge-for-plaid') : __('Sandbox', 'paybridge-for-plaid')) . '</span></h1>';
        if (is_array($result)) {
            echo '<div class="notice ' . esc_attr('connected' === ($result['status'] ?? '') ? 'notice-success' : 'notice-error') . '"><p>' . esc_html(ConnectionTester::message($result)) . '</p></div>';
        }
        echo '<h2>' . esc_html(sprintf(/* translators: %s: status label */ __('Status: %s', 'paybridge-for-plaid'), $status['label'])) . '</h2><ul class="pbfp-checklist">';
        foreach ($status['checks'] as $check) {
            echo '<li class="pbfp-check pbfp-check--' . esc_attr($check['result']) . '"><strong>' . esc_html(ConfigurationStatus::result_label($check['result'])) . '</strong> ' . esc_html($check['label']) . ('' !== $check['help'] ? ' — ' . esc_html($check['help']) : '') . '</li>';
        }
        echo '</ul><table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="' . esc_url($test_url) . '">' . esc_html__('Test Plaid connection', 'paybridge-for-plaid') . '</a></p>';
        echo '<h2>' . esc_html__('Support report', 'paybridge-for-plaid') . '</h2><p id="pbfp-support-help">' . esc_html__('Safe to share: contains no credentials or customer data.', 'paybridge-for-plaid') . '</p>';
        $lines = array();
        foreach ($report as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        echo '<label class="screen-reader-text" for="pbfp-support-report">' . esc_html__('Support report', 'paybridge-for-plaid') . '</label><textarea id="pbfp-support-report" aria-describedby="pbfp-support-help" readonly class="large-text code" rows="16">' . esc_textarea(implode("\n", $lines)) . '</textarea></div>';
    }
}
