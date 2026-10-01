<?php

/** Activation, schema, registration, availability and settings smoke test (runs per HPOS mode). */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Settings\Settings;

global $wpdb;
bmfp_configure();

// Schema v3 exists, is verified and uses only Buckmerce-owned names.
bmfp_assert(Installer::schema_is_valid(), 'Fresh activation must create a valid schema.');
bmfp_assert_same('3', get_option(Installer::OPTION), 'Schema version 3 (account-scoped Plaid event and refund identities).');
foreach (array('buckmerce_plaid_events', 'buckmerce_plaid_payment_locks', 'buckmerce_plaid_refunds') as $suffix) {
    bmfp_assert_same($wpdb->prefix . $suffix, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $suffix)), 'Missing table ' . $suffix);
}
$unique = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}buckmerce_plaid_events WHERE Key_name = 'account_event'", ARRAY_A);
bmfp_assert(array('environment', 'account_fp', 'event_id') === array_column($unique, 'Column_name') && '0' === (string) $unique[0]['Non_unique'], 'Event identity must be UNIQUE(environment, account_fp, event_id).');
bmfp_assert(array() === $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}buckmerce_plaid_events WHERE Key_name = 'environment_event'"), 'No environment-only event identity (it would make another account\'s event a duplicate).');
foreach (array('idempotency_key' => array('idempotency_key'), 'account_refund' => array('environment', 'account_fp', 'refund_id'), 'wc_refund_id' => array('wc_refund_id')) as $key_name => $columns) {
    $index = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$wpdb->prefix}buckmerce_plaid_refunds WHERE Key_name = %s", $key_name), ARRAY_A);
    bmfp_assert($columns === array_column($index, 'Column_name') && '0' === (string) $index[0]['Non_unique'], 'Refund identity must be UNIQUE: ' . $key_name);
}
bmfp_assert(array() === $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}buckmerce_plaid_refunds WHERE Key_name = 'environment_refund'"), 'No environment-only refund identity.');
foreach (array('buckmerce_plaid_events', 'buckmerce_plaid_refunds') as $suffix) {
    bmfp_assert_same('NO', (string) $wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $wpdb->prefix . $suffix, 'account_fp'), ARRAY_A)['Null'], 'account_fp is NOT NULL in ' . $suffix);
}
foreach (array('payment_state', 'account_fp', 'monitor_until') as $column) {
    bmfp_assert(null !== $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->prefix}buckmerce_plaid_payment_locks LIKE %s", $column)), 'Payment index column ' . $column);
}

// HPOS mode under test and compatibility declaration.
$expect_hpos = 'yes' === getenv('BUCKMERCE_PLAID_EXPECT_HPOS');
bmfp_assert_same($expect_hpos, OrderUtil::custom_orders_table_usage_is_enabled(), 'Unexpected HPOS mode.');
$compatible = FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables');
bmfp_assert(in_array('buckmerce-for-plaid/buckmerce-for-plaid.php', $compatible['compatible'] ?? array(), true), 'HPOS compatibility must be declared.');
$blocks = FeaturesUtil::get_compatible_plugins_for_feature('cart_checkout_blocks');
bmfp_assert(in_array('buckmerce-for-plaid/buckmerce-for-plaid.php', $blocks['compatible'] ?? array(), true), 'Checkout Blocks compatibility must be declared.');

// Gateway registration and settings.
$gateway = bmfp_gateway();
bmfp_assert_same('buckmerce_plaid', $gateway->id, 'Gateway ID.');
bmfp_assert_same('woocommerce_buckmerce_plaid_settings', $gateway->get_option_key(), 'Settings option name.');
$fields = array_keys($gateway->get_form_fields());
foreach (array('enabled', 'title', 'description', 'environment', 'client_id', 'secret', 'funding_account_id', 'link_customization_name', 'statement_descriptor', 'network', 'confirmation_state', 'debug', 'delete_data_on_uninstall') as $field) {
    bmfp_assert(in_array($field, $fields, true), 'Missing setting ' . $field);
}
bmfp_assert(! in_array('ach_class', $fields, true), 'ACH class is not configurable: Transfer UI debits are always WEB.');
bmfp_assert(! in_array('reconciliation_enabled', $fields, true), 'Monitoring of existing payments cannot be switched off.');
bmfp_assert($gateway->supports('refunds'), 'Native refunds are supported.');
bmfp_assert($gateway->supports('products'), 'Products are supported.');
foreach ($fields as $field) {
    bmfp_assert(! preg_match('/shop|crypto|direction|system|sci|api_password/i', $field), 'Obsolete setting ' . $field);
}
bmfp_assert_same('Pay by Bank', $gateway->form_fields['title']['default'], 'Default title.');
bmfp_assert_same('Securely pay directly from your bank account.', $gateway->form_fields['description']['default'], 'Default description.');

// The settings screen never renders the stored secret.
$html = $gateway->generate_settings_html($gateway->get_form_fields(), false);
bmfp_assert(! str_contains($html, 'test-sandbox-secret'), 'Stored secret must never be rendered.');
bmfp_assert(str_contains($html, rest_url('buckmerce-for-plaid/v1/webhook')), 'Settings must show the webhook URL.');

// Blank-save keeps the secret; explicit reset removes it.
$key = $gateway->get_field_key('secret');
$post = array();
foreach ($gateway->get_form_fields() as $name => $field) {
    if ('title' !== ($field['type'] ?? '')) {
        $post[$gateway->get_field_key($name)] = 'checkbox' === ($field['type'] ?? '') ? (('yes' === $gateway->get_option($name)) ? '1' : null) : (string) $gateway->get_option($name);
    }
}
$post = array_filter($post, static fn ($value): bool => null !== $value);
$post[$key] = '';
$_POST = $post;
$gateway->process_admin_options();
bmfp_assert_same('test-sandbox-secret', Settings::load()->secret(), 'Blank secret must preserve the stored secret.');
$_POST = $post + array('save' => 'bmfp_reset_secret');
$_POST[$key] = '';
$gateway->process_admin_options();
bmfp_assert_same('', Settings::load()->secret(), 'Reset must clear the stored secret.');
bmfp_configure();
$_POST = $post;
$_POST[$key] = '';
$_POST[$gateway->get_field_key('statement_descriptor')] = 'my-store <b>!';
$gateway->process_admin_options();
bmfp_assert_same('MYSTORE', Settings::load()->statement_descriptor(), 'Statement descriptor is stripped of markup and normalized to Plaid ACH rules.');
$_POST[$gateway->get_field_key('statement_descriptor')] = '€€€';
$gateway->process_admin_options();
bmfp_assert_same('PAYMENT', Settings::load()->statement_descriptor(), 'An unusable descriptor falls back to PAYMENT.');
$_POST = array();
bmfp_configure();

// Availability matrix.
$is_available = static function (array $overrides, string $currency = 'USD'): bool {
    bmfp_configure($overrides);
    update_option('woocommerce_currency', $currency);
    WC()->payment_gateways()->init();
    $gateway = WC()->payment_gateways()->payment_gateways()['buckmerce_plaid'];
    return $gateway->is_available();
};
bmfp_assert($is_available(array()), 'Configured Sandbox USD gateway must be available (Funding Account ID optional).');
bmfp_assert(! $is_available(array('enabled' => 'no')), 'Disabled gateway must be unavailable.');
bmfp_assert(! $is_available(array('client_id' => '')), 'Missing Client ID must hide the gateway.');
bmfp_assert(! $is_available(array('secret' => '')), 'Missing Secret must hide the gateway.');
bmfp_assert(! $is_available(array('environment' => 'development')), 'Invalid environment must hide the gateway.');
bmfp_assert(! $is_available(array(), 'EUR'), 'Unsupported currency must hide the gateway.');
bmfp_assert(! $is_available(array('environment' => 'production')), 'Production without HTTPS must hide the gateway.');
$https_home = static fn ($home) => str_replace('http://', 'https://', (string) $home);
add_filter('option_home', $https_home);
bmfp_assert(! $is_available(array('environment' => 'production', 'link_customization_name' => '')), 'Production without a Link customization must hide the gateway.');
bmfp_assert($is_available(array('environment' => 'production', 'link_customization_name' => 'one_account')), 'Complete Production configuration over HTTPS is available.');
remove_filter('option_home', $https_home);
bmfp_configure();

// REST routes and Blocks registration.
$routes = rest_get_server()->get_routes();
foreach (array('/buckmerce-for-plaid/v1/webhook', '/buckmerce-for-plaid/v1/link-token', '/buckmerce-for-plaid/v1/complete') as $route) {
    bmfp_assert(isset($routes[$route]), 'Missing REST route ' . $route);
}
$registry = Automattic\WooCommerce\Blocks\Package::container()->get(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class);
if (! $registry->is_registered('buckmerce_plaid')) {
    do_action('woocommerce_blocks_payment_method_type_registration', $registry);
}
bmfp_assert($registry->is_registered('buckmerce_plaid'), 'Checkout Blocks payment method must be registered.');
$data = $registry->get_registered('buckmerce_plaid')->get_payment_method_data();
bmfp_assert(true === $data['available'] && 'Pay by Bank' === $data['title'], 'Blocks data must expose availability and title only.');
bmfp_assert(! array_key_exists('directions', $data) && ! str_contains((string) wp_json_encode($data), 'secret'), 'Blocks data must not contain obsolete fields or secrets.');

// Action Scheduler: recurring reconciliation in the Buckmerce group.
( new Scheduler(new Buckmerce\Plaid\Container()) )->ensure_recurring();
bmfp_assert(as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), 'buckmerce-for-plaid'), 'Reconciliation must be scheduled in the buckmerce-for-plaid group.');

// Operational WP-CLI commands.
$cli_commands = WP_CLI::get_root_command()->get_subcommands();
bmfp_assert(isset($cli_commands['buckmerce-plaid']), 'wp buckmerce-plaid is registered.');
$sub = array_keys($cli_commands['buckmerce-plaid']->get_subcommands());
foreach (array('status', 'test-connection', 'sync-events', 'reconcile', 'sync-order', 'refunds', 'simulate-refund', 'fire-sandbox-webhook') as $command) {
    bmfp_assert(in_array($command, $sub, true), 'Missing CLI subcommand ' . $command);
}

// Upgrade from schema 1 (0.1.0): forward-only migration, verified, existing payments keep their account.
$locks_table = $wpdb->prefix . 'buckmerce_plaid_payment_locks';
$wpdb->query("ALTER TABLE {$locks_table} DROP INDEX payment_state, DROP COLUMN payment_state, DROP COLUMN account_fp, DROP COLUMN monitor_until");
$wpdb->query("DROP TABLE {$wpdb->prefix}buckmerce_plaid_refunds");
$wpdb->query($wpdb->prepare("INSERT INTO {$locks_table} (order_id, environment, status, attempts, attempt_id, transfer_intent_id, created_at, updated_at) VALUES (%d, 'sandbox', 'created', 1, %s, 'legacy-intent', UTC_TIMESTAMP(), UTC_TIMESTAMP())", 987654321, str_repeat('c', 32)));
update_option(Installer::OPTION, '1');
bmfp_assert(! Installer::schema_is_valid(), 'A schema 1 database is detected as outdated.');
Installer::install();
bmfp_assert(Installer::schema_is_valid() && '3' === get_option(Installer::OPTION), 'Schema 1 → 3 migration verified.');
bmfp_assert_same(Settings::load()->account_fingerprint(), (string) $wpdb->get_var("SELECT account_fp FROM {$locks_table} WHERE order_id = 987654321"), 'Existing payments are attributed to the configured Plaid account.');
$wpdb->query("DELETE FROM {$locks_table} WHERE order_id = 987654321");

// Only documented Buckmerce options exist (docs/DATA_MODEL.md §9).
global $wpdb;
$documented_options = array(
    'woocommerce_buckmerce_plaid_settings',
    'buckmerce_plaid_schema_version',
    'buckmerce_plaid_last_reconciliation',
    'buckmerce_plaid_last_reconciliation_error',
    'buckmerce_plaid_last_connection_test',
    'buckmerce_plaid_payment_alerts',
    'buckmerce_plaid_last_webhook',
    'buckmerce_plaid_last_webhook_rejection',
    'buckmerce_plaid_last_link_token_error',
);
// Per Plaid account (environment + 16-hex account fingerprint, ADR-0018).
$scoped_option = '/^buckmerce_plaid_(event_cursor|first_intent_at|event_sync)_(sandbox|production)_[a-f0-9]{16}$/';
$buckmerce_options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", 'buckmerce%', 'woocommerce_buckmerce%'));
$undocumented = array_filter(array_diff($buckmerce_options, $documented_options), static fn (string $name): bool => 1 !== preg_match($scoped_option, $name));
bmfp_assert(array() === $undocumented, 'Undocumented Buckmerce options: ' . implode(', ', $undocumented));
foreach (array('buckmerce_plaid_event_cursor_sandbox', 'buckmerce_plaid_first_intent_at_sandbox', 'buckmerce_plaid_last_event_sync', 'buckmerce_plaid_event_sync_failures') as $legacy) {
    bmfp_assert(false === get_option($legacy), 'A fresh store never writes the schema-2 per-environment option ' . $legacy);
}
WP_CLI::success('Buckmerce smoke test passed (HPOS=' . ($expect_hpos ? 'yes' : 'no') . ').');
