<?php

/**
 * Uninstall is deliberately conservative:
 * - Buckmerce scheduled actions are always removed;
 * - settings, event and refund history and tables are deleted only when the
 *   merchant enabled "Uninstall cleanup";
 * - payment records stored on WooCommerce orders are never deleted;
 * - nothing owned by another plugin is touched.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (function_exists('as_unschedule_all_actions')) {
    foreach (array('buckmerce_plaid_transfer_event_sync', 'buckmerce_plaid_reconcile', 'buckmerce_plaid_reconcile_continue') as $bmfp_hook) {
        as_unschedule_all_actions($bmfp_hook, array(), 'buckmerce-plaid');
    }
}

$bmfp_settings = get_option('woocommerce_buckmerce_plaid_settings', array());
if (! is_array($bmfp_settings) || 'yes' !== ($bmfp_settings['delete_data_on_uninstall'] ?? 'no')) {
    return;
}

foreach (
    array(
        'woocommerce_buckmerce_plaid_settings',
        'buckmerce_plaid_schema_version',
        'buckmerce_plaid_last_reconciliation',
        'buckmerce_plaid_last_reconciliation_error',
        'buckmerce_plaid_last_connection_test',
        'buckmerce_plaid_payment_alerts',
        'buckmerce_plaid_last_webhook',
        'buckmerce_plaid_last_webhook_rejection',
        'buckmerce_plaid_last_link_token_error',
        // Schema-2 options kept after the upgrade to schema 3 for auditing.
        'buckmerce_plaid_event_cursor_sandbox',
        'buckmerce_plaid_event_cursor_production',
        'buckmerce_plaid_first_intent_at_sandbox',
        'buckmerce_plaid_first_intent_at_production',
        'buckmerce_plaid_last_event_sync',
        'buckmerce_plaid_last_event_sync_error',
        'buckmerce_plaid_event_sync_failures',
    ) as $bmfp_option
) {
    delete_option($bmfp_option);
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Opt-in uninstall cleanup of plugin-owned data only.

// Per Plaid account options (event cursor, payment epoch, event-sync health):
// buckmerce_plaid_{kind}_{environment}_{16-hex account fingerprint}. Matched exactly, never by prefix guessing.
foreach (array('buckmerce_plaid_event_cursor_', 'buckmerce_plaid_first_intent_at_', 'buckmerce_plaid_event_sync_') as $bmfp_prefix) {
    $bmfp_names = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like($bmfp_prefix) . '%'
        )
    );
    foreach (is_array($bmfp_names) ? $bmfp_names : array() as $bmfp_name) {
        if (is_string($bmfp_name) && 1 === preg_match('/^buckmerce_plaid_(event_cursor|first_intent_at|event_sync)_(sandbox|production)_[a-f0-9]{16}$/', $bmfp_name)) {
            delete_option($bmfp_name);
        }
    }
}

// Transients created by Buckmerce use the plugin-owned "bmfp_" prefix. Resolve exact
// names first so delete_transient() also clears any persistent object cache.
foreach (array('_transient_bmfp_', '_transient_timeout_bmfp_') as $bmfp_prefix) {
    $bmfp_names = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like($bmfp_prefix) . '%'
        )
    );
    foreach (is_array($bmfp_names) ? $bmfp_names : array() as $bmfp_name) {
        if (is_string($bmfp_name) && str_starts_with($bmfp_name, '_transient_bmfp_')) {
            delete_transient(substr($bmfp_name, strlen('_transient_')));
        }
    }
}

// Table names derive only from the trusted WordPress prefix and fixed Buckmerce suffixes.
foreach (array('buckmerce_plaid_events', 'buckmerce_plaid_payment_locks', 'buckmerce_plaid_refunds') as $bmfp_table_suffix) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Merchant opted in to removing Buckmerce-owned tables.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $bmfp_table_suffix));
}
