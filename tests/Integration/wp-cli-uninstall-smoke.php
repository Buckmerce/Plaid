<?php

/** Uninstall ownership boundaries (run after deactivation, with the plugin files still installed). */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

global $wpdb;
$uninstall = WP_PLUGIN_DIR . '/paybridge-for-plaid/uninstall.php';
pbfp_assert(is_file($uninstall), 'The installed ZIP must contain uninstall.php.');

// Deactivation removed only PayBridge scheduled actions.
foreach (array('paybridge_plaid_transfer_event_sync', 'paybridge_plaid_reconcile', 'paybridge_plaid_reconcile_continue') as $hook) {
    pbfp_assert(! as_has_scheduled_action($hook, array(), 'paybridge-for-plaid'), 'Deactivation must unschedule ' . $hook);
}

// Decoys owned by other plugins must survive every uninstall mode.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}other_gateway_events (id int)");
update_option('woocommerce_other_gateway_settings', array('enabled' => 'yes'), false);
update_option('paybridge_plaid_unrelated_by_other_plugin', 'keep', false);
set_transient('pbfpx_other_plugin_cache', 'keep', 3600);
as_schedule_single_action(time() + 3600, 'other_plugin_hook', array(), 'other-plugin');
$order = pbfp_order('11.11');
$order->update_meta_data('_pbfp_transfer_id', 'audit-transfer');
$order->save();

$run_uninstall = static function () use ($uninstall): void {
    if (! defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', 'paybridge-for-plaid/paybridge-for-plaid.php');
    }
    include $uninstall;
};

// Default: financial/audit data retained.
pbfp_configure(array('delete_data_on_uninstall' => 'no'));
set_transient('pbfp_connection_test_1', array('status' => 'connected'), 600);
$run_uninstall();
pbfp_assert(false !== get_option('woocommerce_paybridge_plaid_settings'), 'Settings retained by default.');
pbfp_assert($wpdb->prefix . 'paybridge_plaid_events' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}paybridge_plaid_events'"), 'Event table retained by default.');
pbfp_assert($wpdb->prefix . 'paybridge_plaid_refunds' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}paybridge_plaid_refunds'"), 'Refund history retained by default.');

// Explicit cleanup: only PayBridge-owned data is removed.
pbfp_configure(array('delete_data_on_uninstall' => 'yes'));
update_option('paybridge_plaid_last_reconciliation', gmdate('c'), false);
$scoped = array('paybridge_plaid_event_cursor_production_0123456789abcdef', 'paybridge_plaid_first_intent_at_sandbox_fedcba9876543210', 'paybridge_plaid_event_sync_production_0123456789abcdef', 'paybridge_plaid_event_cursor_sandbox');
foreach ($scoped as $name) {
    update_option($name, '42', false);
}
update_option('paybridge_plaid_event_cursor_unrelated_by_other_plugin', 'keep', false);
$run_uninstall();
foreach ($scoped as $name) {
    pbfp_assert(false === get_option($name), 'Per-account and schema-2 options removed on cleanup: ' . $name);
}
pbfp_assert('keep' === get_option('paybridge_plaid_event_cursor_unrelated_by_other_plugin'), 'Per-account cleanup matches the exact option pattern only.');
pbfp_assert(false === get_option('woocommerce_paybridge_plaid_settings'), 'Settings removed on cleanup.');
pbfp_assert(false === get_option('paybridge_plaid_schema_version'), 'Schema version removed on cleanup.');
pbfp_assert(false === get_option('paybridge_plaid_last_reconciliation'), 'Operational options removed on cleanup.');
pbfp_assert(false === get_transient('pbfp_connection_test_1'), 'PayBridge transients removed on cleanup.');
foreach (array('paybridge_plaid_events', 'paybridge_plaid_payment_locks', 'paybridge_plaid_refunds') as $suffix) {
    pbfp_assert(null === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$suffix}'"), 'Table removed on cleanup: ' . $suffix);
}
pbfp_assert($wpdb->prefix . 'other_gateway_events' === $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}other_gateway_events'"), 'Another plugin table must never be dropped.');
pbfp_assert(false !== get_option('woocommerce_other_gateway_settings'), 'Another plugin option must never be deleted.');
pbfp_assert('keep' === get_option('paybridge_plaid_unrelated_by_other_plugin'), 'Unlisted options are not deleted by prefix guessing.');
pbfp_assert('keep' === get_transient('pbfpx_other_plugin_cache'), 'Similar-prefix transients of other plugins survive.');
pbfp_assert(as_has_scheduled_action('other_plugin_hook', array(), 'other-plugin'), 'Other plugins scheduled actions survive.');
pbfp_assert_same('audit-transfer', (string) pbfp_reload($order)->get_meta('_pbfp_transfer_id', true), 'Order payment records are always retained.');
WP_CLI::success('PayBridge uninstall smoke passed.');
