<?php

/**
 * Uninstall is deliberately conservative (docs/DATA_MODEL.md §8):
 * - PayBridge scheduled actions are always removed;
 * - settings, event history and tables are deleted only when the merchant
 *   enabled "Uninstall cleanup";
 * - payment records stored on WooCommerce orders are never deleted;
 * - nothing owned by another plugin is touched.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (function_exists('as_unschedule_all_actions')) {
    foreach (array('paybridge_plaid_transfer_event_sync', 'paybridge_plaid_reconcile', 'paybridge_plaid_reconcile_continue') as $pbfp_hook) {
        as_unschedule_all_actions($pbfp_hook, array(), 'paybridge-for-plaid');
    }
}

$pbfp_settings = get_option('woocommerce_paybridge_plaid_settings', array());
if (! is_array($pbfp_settings) || 'yes' !== ($pbfp_settings['delete_data_on_uninstall'] ?? 'no')) {
    return;
}

foreach (
    array(
        'woocommerce_paybridge_plaid_settings',
        'paybridge_plaid_schema_version',
        'paybridge_plaid_event_cursor_sandbox',
        'paybridge_plaid_event_cursor_production',
        'paybridge_plaid_last_event_sync',
        'paybridge_plaid_last_event_sync_error',
        'paybridge_plaid_last_reconciliation',
        'paybridge_plaid_last_reconciliation_error',
        'paybridge_plaid_last_connection_test',
        'paybridge_plaid_payment_alerts',
    ) as $pbfp_option
) {
    delete_option($pbfp_option);
}

global $wpdb;

// Transients created by PayBridge use the plugin-owned "pbfp_" prefix. Resolve exact
// names first so delete_transient() also clears any persistent object cache.
foreach (array('_transient_pbfp_', '_transient_timeout_pbfp_') as $pbfp_prefix) {
    $pbfp_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($pbfp_prefix) . '%'
        )
    );
    foreach (is_array($pbfp_names) ? $pbfp_names : array() as $pbfp_name) {
        if (is_string($pbfp_name) && str_starts_with($pbfp_name, '_transient_pbfp_')) {
            delete_transient(substr($pbfp_name, strlen('_transient_')));
        }
    }
}

// Table names derive only from the trusted WordPress prefix and fixed PayBridge suffixes.
foreach (array('paybridge_plaid_events', 'paybridge_plaid_payment_locks') as $pbfp_table_suffix) {
    $pbfp_table = $wpdb->prefix . $pbfp_table_suffix;
    $wpdb->query("DROP TABLE IF EXISTS {$pbfp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
