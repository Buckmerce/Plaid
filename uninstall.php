<?php

/**
 * Uninstall is deliberately conservative (docs/DATA_MODEL.md §8):
 * - PayBridge scheduled actions are always removed;
 * - settings, event and refund history and tables are deleted only when the
 *   merchant enabled "Uninstall cleanup";
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
        'paybridge_plaid_last_reconciliation',
        'paybridge_plaid_last_reconciliation_error',
        'paybridge_plaid_last_connection_test',
        'paybridge_plaid_payment_alerts',
        'paybridge_plaid_last_webhook',
        'paybridge_plaid_last_webhook_rejection',
        'paybridge_plaid_last_link_token_error',
        // Schema-2 options kept after the upgrade to schema 3 for auditing.
        'paybridge_plaid_event_cursor_sandbox',
        'paybridge_plaid_event_cursor_production',
        'paybridge_plaid_first_intent_at_sandbox',
        'paybridge_plaid_first_intent_at_production',
        'paybridge_plaid_last_event_sync',
        'paybridge_plaid_last_event_sync_error',
        'paybridge_plaid_event_sync_failures',
    ) as $pbfp_option
) {
    delete_option($pbfp_option);
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Opt-in uninstall cleanup of plugin-owned data only.

// Per Plaid account options (event cursor, payment epoch, event-sync health):
// paybridge_plaid_{kind}_{environment}_{16-hex account fingerprint}. Matched exactly, never by prefix guessing.
foreach (array('paybridge_plaid_event_cursor_', 'paybridge_plaid_first_intent_at_', 'paybridge_plaid_event_sync_') as $pbfp_prefix) {
    $pbfp_names = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like($pbfp_prefix) . '%'
        )
    );
    foreach (is_array($pbfp_names) ? $pbfp_names : array() as $pbfp_name) {
        if (is_string($pbfp_name) && 1 === preg_match('/^paybridge_plaid_(event_cursor|first_intent_at|event_sync)_(sandbox|production)_[a-f0-9]{16}$/', $pbfp_name)) {
            delete_option($pbfp_name);
        }
    }
}

// Transients created by PayBridge use the plugin-owned "pbfp_" prefix. Resolve exact
// names first so delete_transient() also clears any persistent object cache.
foreach (array('_transient_pbfp_', '_transient_timeout_pbfp_') as $pbfp_prefix) {
    $pbfp_names = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
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
foreach (array('paybridge_plaid_events', 'paybridge_plaid_payment_locks', 'paybridge_plaid_refunds') as $pbfp_table_suffix) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Merchant opted in to removing PayBridge-owned tables.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $pbfp_table_suffix));
}
