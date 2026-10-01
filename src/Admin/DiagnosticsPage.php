<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\ReconciliationService;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Gateway\GatewayAvailability;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Persistence\PaymentLockStatus;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Persistence\TransferEventStore;
use Buckmerce\Plaid\REST\RestRoutes;
use Buckmerce\Plaid\REST\WebhookController;
use Buckmerce\Plaid\Settings\Settings;

/**
 * WooCommerce → Buckmerce diagnostics. Configuration booleans, health timestamps and
 * operational counts from indexed Buckmerce tables — never secrets, never customer data,
 * never a Plaid call on page view.
 */
final class DiagnosticsPage
{
    public const PAGE_SLUG = 'buckmerce-plaid-diagnostics';

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
        add_submenu_page('woocommerce', __('Buckmerce diagnostics', 'buckmerce-for-plaid'), __('Buckmerce diagnostics', 'buckmerce-for-plaid'), 'manage_woocommerce', self::PAGE_SLUG, array($this, 'render'));
    }

    /** @return array<string, string> */
    public static function report(): array
    {
        $settings = Settings::load();
        $yes = __('Yes', 'buckmerce-for-plaid');
        $no = __('No', 'buckmerce-for-plaid');
        $never = __('Never', 'buckmerce-for-plaid');
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
            __('Status', 'buckmerce-for-plaid') => $status['label'],
            __('Plugin version', 'buckmerce-for-plaid') => BUCKMERCE_PLAID_VERSION,
            __('PHP', 'buckmerce-for-plaid') => PHP_VERSION,
            __('WordPress', 'buckmerce-for-plaid') => (string) get_bloginfo('version'),
            __('WooCommerce', 'buckmerce-for-plaid') => defined('WC_VERSION') ? (string) WC_VERSION : __('Not active', 'buckmerce-for-plaid'),
            __('Database server', 'buckmerce-for-plaid') => self::database_version(),
            __('HPOS (custom order tables)', 'buckmerce-for-plaid') => class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled() ? $yes : $no,
            __('Checkout Blocks available', 'buckmerce-for-plaid') => class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') ? $yes : $no,
            __('HTTPS', 'buckmerce-for-plaid') => GatewayAvailability::site_uses_https() ? $yes : $no,
            __('REST API route registered', 'buckmerce-for-plaid') => isset(rest_get_server()->get_routes()['/' . RestRoutes::NAMESPACE . '/webhook']) ? $yes : $no,
            __('Action Scheduler', 'buckmerce-for-plaid') => function_exists('as_schedule_single_action') ? $yes : $no,
            __('WP-Cron disabled (DISABLE_WP_CRON)', 'buckmerce-for-plaid') => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? $yes : $no,
            __('Background maintenance active', 'buckmerce-for-plaid') => Scheduler::maintenance_active($settings) ? $yes : $no,
            __('Reconciliation scheduled', 'buckmerce-for-plaid') => function_exists('as_has_scheduled_action') && as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP) ? $yes : $no,
            __('Overdue Buckmerce jobs (>30 min)', 'buckmerce-for-plaid') => (string) ConfigurationStatus::stalled_actions(),
            __('Failed Buckmerce jobs (7 days)', 'buckmerce-for-plaid') => (string) ConfigurationStatus::failed_actions(),
            __('Database schema', 'buckmerce-for-plaid') => $schema_ok ? sprintf(/* translators: %s: schema version */ __('Valid (version %s)', 'buckmerce-for-plaid'), (string) get_option(Installer::OPTION, '')) : __('INVALID', 'buckmerce-for-plaid'),
            __('Gateway enabled (new payments)', 'buckmerce-for-plaid') => $settings->enabled() ? $yes : $no,
            __('Plaid environment', 'buckmerce-for-plaid') => $environment,
            __('Client ID configured', 'buckmerce-for-plaid') => '' !== $settings->client_id() ? $yes : $no,
            __('Secret configured', 'buckmerce-for-plaid') => '' !== $settings->secret() ? $yes : $no,
            __('Plaid account fingerprint', 'buckmerce-for-plaid') => '' === $settings->account_fingerprint() ? '-' : $settings->account_fingerprint(),
            __('Funding Account configured', 'buckmerce-for-plaid') => '' !== $settings->funding_account_id() ? $yes : __('No (Plaid Ledger)', 'buckmerce-for-plaid'),
            __('Link customization configured', 'buckmerce-for-plaid') => '' !== $settings->link_customization_name() ? __('PASS', 'buckmerce-for-plaid') : __('FAIL (required by Transfer UI in Sandbox and Production)', 'buckmerce-for-plaid'),
            __('Last Link session error', 'buckmerce-for-plaid') => is_array($link_error) && isset($link_error['at']) ? sprintf('%s %s', (string) $link_error['at'], (string) ($link_error['code'] ?? '')) : $never,
            __('Bank statement description', 'buckmerce-for-plaid') => $settings->statement_descriptor(),
            __('ACH class', 'buckmerce-for-plaid') => strtoupper($settings->ach_class()),
            __('Plaid connectivity (last test)', 'buckmerce-for-plaid') => is_array($connection) && array() !== $connection ? ConnectionTester::message($connection) . ' ' . (string) ($connection['at'] ?? '') : __('Not tested', 'buckmerce-for-plaid'),
            __('Webhook URL', 'buckmerce-for-plaid') => rest_url(RestRoutes::NAMESPACE . '/webhook'),
            __('Last verified webhook', 'buckmerce-for-plaid') => is_array($webhook) && isset($webhook['at']) ? sprintf('%s %s (%s)', (string) $webhook['at'], (string) ($webhook['code'] ?? ''), (string) ($webhook['outcome'] ?? '')) : $never,
            __('Last rejected webhook', 'buckmerce-for-plaid') => is_array($rejection) && isset($rejection['at']) ? sprintf('%s %s (HTTP %d)', (string) $rejection['at'], (string) ($rejection['reason'] ?? ''), (int) ($rejection['status'] ?? 0)) : $never,
            __('Event stream (environment/account)', 'buckmerce-for-plaid') => $scope->is_valid() ? $scope->environment . ' / ' . $scope->account_fp : '-',
            __('Event stream cursor (last stored event ID)', 'buckmerce-for-plaid') => $scope->is_valid() ? ( new EventCursor() )->get($scope) : '-',
            __('First payment with this account (epoch)', 'buckmerce-for-plaid') => null === $epoch ? $never : gmdate('c', $epoch),
            __('Last successful event sync', 'buckmerce-for-plaid') => '' === $sync['last_sync'] ? $never : $sync['last_sync'],
            __('Last event sync error', 'buckmerce-for-plaid') => isset($sync_error['at']) ? sprintf('%s %s (%s)', $sync_error['at'], $sync_error['code'] ?? '', $sync_error['category'] ?? '') : $never,
            __('Consecutive event sync failures', 'buckmerce-for-plaid') => (string) $sync['failures'],
            __('Last reconciliation', 'buckmerce-for-plaid') => (string) get_option(ReconciliationService::LAST_RUN_OPTION, $never),
            __('Last reconciliation error', 'buckmerce-for-plaid') => is_array($reconcile_error) && isset($reconcile_error['at']) ? sprintf('%s (%s)', (string) $reconcile_error['at'], (string) ($reconcile_error['category'] ?? '')) : $never,
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
            __('Monitored bank payments', 'buckmerce-for-plaid') => (string) $monitored['count'],
            __('Oldest monitored payment (attempt created)', 'buckmerce-for-plaid') => '' === $monitored['oldest'] ? '-' : $monitored['oldest'] . ' UTC',
            __('Payments in flight (pending/posted)', 'buckmerce-for-plaid') => (string) $sum($states, array('transfer_created', 'pending', 'posted')),
            __('Payments awaiting authorization', 'buckmerce-for-plaid') => (string) $sum($states, array('intent_created', 'intent_pending')),
            __('Payments in manual review', 'buckmerce-for-plaid') => (string) ($states['manual_review'] ?? 0),
            __('Returned payments', 'buckmerce-for-plaid') => (string) ($states['returned'] ?? 0),
            __('Intent creations with unknown outcome', 'buckmerce-for-plaid') => (string) $locks->count_by_lock_status(PaymentLockStatus::UNCERTAIN),
            __('Payments of another Plaid account', 'buckmerce-for-plaid') => (string) $locks->count_other_account($environment, $settings->account_fingerprint()),
            __('Refunds pending (in flight/unconfirmed)', 'buckmerce-for-plaid') => (string) $sum($refund_counts, array('creating', 'uncertain', 'pending', 'posted')),
            __('Refunds settled', 'buckmerce-for-plaid') => (string) ($refund_counts['settled'] ?? 0),
            __('Refunds failed or returned', 'buckmerce-for-plaid') => (string) $sum($refund_counts, array('failed', 'returned')),
            __('Event backlog (waiting to be processed)', 'buckmerce-for-plaid') => (string) $sum($event_counts, array(TransferEventStore::RECEIVED, TransferEventStore::RETRY, TransferEventStore::PROCESSING)),
            __('Events waiting for an order match', 'buckmerce-for-plaid') => (string) ($event_counts[TransferEventStore::UNMATCHED] ?? 0),
            __('Events abandoned after retries', 'buckmerce-for-plaid') => (string) ($event_counts[TransferEventStore::ABANDONED] ?? 0),
            __('Events of previous accounts or schema 2 (audit only)', 'buckmerce-for-plaid') => (string) $events->count_outside($scope),
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
        echo '<div class="wrap"><h1>' . esc_html__('Buckmerce for Plaid — diagnostics', 'buckmerce-for-plaid') . ' <span class="bmfp-badge bmfp-badge--' . esc_attr($settings->is_production() ? 'production' : 'sandbox') . '">' . esc_html($settings->is_production() ? __('Production', 'buckmerce-for-plaid') : __('Sandbox', 'buckmerce-for-plaid')) . '</span></h1>';
        if (is_array($result)) {
            echo '<div class="notice ' . esc_attr('connected' === ($result['status'] ?? '') ? 'notice-success' : 'notice-error') . '"><p>' . esc_html(ConnectionTester::message($result)) . '</p></div>';
        }
        echo '<h2>' . esc_html(sprintf(/* translators: %s: status label */ __('Status: %s', 'buckmerce-for-plaid'), $status['label'])) . '</h2><ul class="bmfp-checklist">';
        foreach ($status['checks'] as $check) {
            echo '<li class="bmfp-check bmfp-check--' . esc_attr($check['result']) . '"><strong>' . esc_html(ConfigurationStatus::result_label($check['result'])) . '</strong> ' . esc_html($check['label']) . ('' !== $check['help'] ? ' — ' . esc_html($check['help']) : '') . '</li>';
        }
        echo '</ul><table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="' . esc_url($test_url) . '">' . esc_html__('Test Plaid connection', 'buckmerce-for-plaid') . '</a></p>';
        echo '<h2>' . esc_html__('Support report', 'buckmerce-for-plaid') . '</h2><p id="bmfp-support-help">' . esc_html__('Safe to share: contains no credentials or customer data.', 'buckmerce-for-plaid') . '</p>';
        $lines = array();
        foreach ($report as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        echo '<label class="screen-reader-text" for="bmfp-support-report">' . esc_html__('Support report', 'buckmerce-for-plaid') . '</label><textarea id="bmfp-support-report" aria-describedby="bmfp-support-help" readonly class="large-text code" rows="16">' . esc_textarea(implode("\n", $lines)) . '</textarea></div>';
    }
}
