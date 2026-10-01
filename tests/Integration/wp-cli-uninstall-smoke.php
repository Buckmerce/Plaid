<?php

/** Uninstall ownership boundaries (run after deactivation, with the plugin files still installed). */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

global $wpdb;
$uninstall = WP_PLUGIN_DIR . '/buckmerce-for-plaid/uninstall.php';
bmfp_assert(is_file($uninstall), 'The installed ZIP must contain uninstall.php.');

// Deactivation removed only Buckmerce scheduled actions.
foreach (array('buckmerce_plaid_transfer_event_sync', 'buckmerce_plaid_reconcile', 'buckmerce_plaid_reconcile_continue') as $hook) {
    bmfp_assert(! as_has_scheduled_action($hook, array(), 'buckmerce-for-plaid'), 'Deactivation must unschedule ' . $hook);
}

// Decoys owned by other plugins must survive every uninstall mode.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}other_gateway_events (id int)");
update_option('woocommerce_other_gateway_settings', array('enabled' => 'yes'), false);
update_option('buckmerce_plaid_unrelated_by_other_plugin', 'keep', false);
set_transient('bmfpx_other_plugin_cache', 'keep', 3600);
as_schedule_single_action(time() + 3600, 'other_plugin_hook', array(), 'other-plugin');
$order = bmfp_order('11.11');
$order->update_meta_data('_bmfp_transfer_id', 'audit-transfer');
$order->save();

$run_uninstall = static function () use ($uninstall): void {
    if (! defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', 'buckmerce-for-plaid/buckmerce-for-plaid.php');
    }
    include $uninstall;
};

// Default: financial/audit data retained.
bmfp_configure(array('delete_data_on_uninstall' => 'no'));
set_transient('bmfp_connection_test_1', array('status' => 'connected'), 600);
$run_uninstall();
bmfp_assert(false !== get_option('woocommerce_buckmerce_plaid_settings'), 'Settings retained by default.');
bmfp_assert($wpdb->prefix . 'buckmerce_plaid_events' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}buckmerce_plaid_events'"), 'Event table retained by default.');
bmfp_assert($wpdb->prefix . 'buckmerce_plaid_refunds' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}buckmerce_plaid_refunds'"), 'Refund history retained by default.');

// Explicit cleanup: only Buckmerce-owned data is removed.
bmfp_configure(array('delete_data_on_uninstall' => 'yes'));
update_option('buckmerce_plaid_last_reconciliation', gmdate('c'), false);
$scoped = array('buckmerce_plaid_event_cursor_production_0123456789abcdef', 'buckmerce_plaid_first_intent_at_sandbox_fedcba9876543210', 'buckmerce_plaid_event_sync_production_0123456789abcdef', 'buckmerce_plaid_event_cursor_sandbox');
foreach ($scoped as $name) {
    update_option($name, '42', false);
}
update_option('buckmerce_plaid_event_cursor_unrelated_by_other_plugin', 'keep', false);
$run_uninstall();
foreach ($scoped as $name) {
    bmfp_assert(false === get_option($name), 'Per-account and schema-2 options removed on cleanup: ' . $name);
}
bmfp_assert('keep' === get_option('buckmerce_plaid_event_cursor_unrelated_by_other_plugin'), 'Per-account cleanup matches the exact option pattern only.');
bmfp_assert(false === get_option('woocommerce_buckmerce_plaid_settings'), 'Settings removed on cleanup.');
bmfp_assert(false === get_option('buckmerce_plaid_schema_version'), 'Schema version removed on cleanup.');
bmfp_assert(false === get_option('buckmerce_plaid_last_reconciliation'), 'Operational options removed on cleanup.');
bmfp_assert(false === get_transient('bmfp_connection_test_1'), 'Buckmerce transients removed on cleanup.');
foreach (array('buckmerce_plaid_events', 'buckmerce_plaid_payment_locks', 'buckmerce_plaid_refunds') as $suffix) {
    bmfp_assert(null === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$suffix}'"), 'Table removed on cleanup: ' . $suffix);
}
bmfp_assert($wpdb->prefix . 'other_gateway_events' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}other_gateway_events'"), 'Another plugin table must never be dropped.');
bmfp_assert(false !== get_option('woocommerce_other_gateway_settings'), 'Another plugin option must never be deleted.');
bmfp_assert('keep' === get_option('buckmerce_plaid_unrelated_by_other_plugin'), 'Unlisted options are not deleted by prefix guessing.');
bmfp_assert('keep' === get_transient('bmfpx_other_plugin_cache'), 'Similar-prefix transients of other plugins survive.');
bmfp_assert(as_has_scheduled_action('other_plugin_hook', array(), 'other-plugin'), 'Other plugins scheduled actions survive.');
bmfp_assert_same('audit-transfer', (string) bmfp_reload($order)->get_meta('_bmfp_transfer_id', true), 'Order payment records are always retained.');
WP_CLI::success('Buckmerce uninstall smoke passed.');
