<?php

/** Activation, schema, registration, availability and settings smoke test (runs per HPOS mode). */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Settings\Settings;

global $wpdb;
pbfp_configure();

// Schema v1 exists, is verified and uses only PayBridge-owned names.
pbfp_assert(Installer::schema_is_valid(), 'Fresh activation must create a valid schema.');
pbfp_assert_same('1', get_option(Installer::OPTION), 'Schema version must start at 1.');
foreach (array('paybridge_plaid_events', 'paybridge_plaid_payment_locks') as $suffix) {
    pbfp_assert_same($wpdb->prefix . $suffix, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $suffix)), 'Missing table ' . $suffix);
}
$unique = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}paybridge_plaid_events WHERE Key_name = 'environment_event'", ARRAY_A);
pbfp_assert(2 === count($unique) && '0' === (string) $unique[0]['Non_unique'], 'Event identity must be UNIQUE(environment, event_id).');

// HPOS mode under test and compatibility declaration.
$expect_hpos = 'yes' === getenv('PAYBRIDGE_PLAID_EXPECT_HPOS');
pbfp_assert_same($expect_hpos, OrderUtil::custom_orders_table_usage_is_enabled(), 'Unexpected HPOS mode.');
$compatible = FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables');
pbfp_assert(in_array('paybridge-for-plaid/paybridge-for-plaid.php', $compatible['compatible'] ?? array(), true), 'HPOS compatibility must be declared.');
$blocks = FeaturesUtil::get_compatible_plugins_for_feature('cart_checkout_blocks');
pbfp_assert(in_array('paybridge-for-plaid/paybridge-for-plaid.php', $blocks['compatible'] ?? array(), true), 'Checkout Blocks compatibility must be declared.');

// Gateway registration and settings.
$gateway = pbfp_gateway();
pbfp_assert_same('paybridge_plaid', $gateway->id, 'Gateway ID.');
pbfp_assert_same('woocommerce_paybridge_plaid_settings', $gateway->get_option_key(), 'Settings option name.');
$fields = array_keys($gateway->get_form_fields());
foreach (array('enabled', 'title', 'description', 'environment', 'client_id', 'secret', 'funding_account_id', 'network', 'ach_class', 'debug', 'reconciliation_enabled', 'delete_data_on_uninstall') as $field) {
    pbfp_assert(in_array($field, $fields, true), 'Missing setting ' . $field);
}
foreach ($fields as $field) {
    pbfp_assert(! preg_match('/shop|crypto|direction|system|sci|api_password/i', $field), 'Obsolete setting ' . $field);
}
pbfp_assert_same('Pay by Bank', $gateway->form_fields['title']['default'], 'Default title.');
pbfp_assert_same('Securely pay directly from your bank account.', $gateway->form_fields['description']['default'], 'Default description.');

// The settings screen never renders the stored secret.
$html = $gateway->generate_settings_html($gateway->get_form_fields(), false);
pbfp_assert(! str_contains($html, 'test-sandbox-secret'), 'Stored secret must never be rendered.');
pbfp_assert(str_contains($html, rest_url('paybridge-for-plaid/v1/webhook')), 'Settings must show the webhook URL.');

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
pbfp_assert_same('test-sandbox-secret', Settings::load()->secret(), 'Blank secret must preserve the stored secret.');
$_POST = $post + array('save' => 'pbfp_reset_secret');
$_POST[$key] = '';
$gateway->process_admin_options();
pbfp_assert_same('', Settings::load()->secret(), 'Reset must clear the stored secret.');
$_POST = array();
pbfp_configure();

// Availability matrix.
$is_available = static function (array $overrides, string $currency = 'USD'): bool {
    pbfp_configure($overrides);
    update_option('woocommerce_currency', $currency);
    WC()->payment_gateways()->init();
    $gateway = WC()->payment_gateways()->payment_gateways()['paybridge_plaid'];
    return $gateway->is_available();
};
pbfp_assert($is_available(array()), 'Configured Sandbox USD gateway must be available (Funding Account ID optional).');
pbfp_assert(! $is_available(array('enabled' => 'no')), 'Disabled gateway must be unavailable.');
pbfp_assert(! $is_available(array('client_id' => '')), 'Missing Client ID must hide the gateway.');
pbfp_assert(! $is_available(array('secret' => '')), 'Missing Secret must hide the gateway.');
pbfp_assert(! $is_available(array('environment' => 'development')), 'Invalid environment must hide the gateway.');
pbfp_assert(! $is_available(array(), 'EUR'), 'Unsupported currency must hide the gateway.');
pbfp_assert(! $is_available(array('environment' => 'production')), 'Production without HTTPS must hide the gateway.');
pbfp_configure();

// REST routes and Blocks registration.
$routes = rest_get_server()->get_routes();
foreach (array('/paybridge-for-plaid/v1/webhook', '/paybridge-for-plaid/v1/link-token', '/paybridge-for-plaid/v1/complete') as $route) {
    pbfp_assert(isset($routes[$route]), 'Missing REST route ' . $route);
}
$registry = Automattic\WooCommerce\Blocks\Package::container()->get(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class);
if (! $registry->is_registered('paybridge_plaid')) {
    do_action('woocommerce_blocks_payment_method_type_registration', $registry);
}
pbfp_assert($registry->is_registered('paybridge_plaid'), 'Checkout Blocks payment method must be registered.');
$data = $registry->get_registered('paybridge_plaid')->get_payment_method_data();
pbfp_assert(true === $data['available'] && 'Pay by Bank' === $data['title'], 'Blocks data must expose availability and title only.');
pbfp_assert(! array_key_exists('directions', $data) && ! str_contains((string) wp_json_encode($data), 'secret'), 'Blocks data must not contain obsolete fields or secrets.');

// Action Scheduler: recurring reconciliation in the PayBridge group.
( new Scheduler(new PayBridge\Plaid\Container()) )->ensure_recurring();
pbfp_assert(as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), 'paybridge-for-plaid'), 'Reconciliation must be scheduled in the paybridge-for-plaid group.');

// No legacy identity was created.
foreach (array('woocommerce_paykassa_settings', 'paykassa_schema_version') as $legacy) {
    pbfp_assert(false === get_option($legacy), 'Legacy option must not exist: ' . $legacy);
}
WP_CLI::success('PayBridge smoke test passed (HPOS=' . ($expect_hpos ? 'yes' : 'no') . ').');
