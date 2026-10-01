<?php

/** Shared helpers for WP-CLI integration scripts (run with `wp eval-file`). */

declare(strict_types=1);

const PBFP_TEST_SETTINGS_OPTION = 'woocommerce_paybridge_plaid_settings';
const PBFP_TEST_GATEWAY_ID = 'paybridge_plaid';

if (! defined('PAYBRIDGE_PLAID_TEST_DATABASE') || true !== PAYBRIDGE_PLAID_TEST_DATABASE || ! str_starts_with(DB_NAME, 'paybridge_')) {
    throw new RuntimeException('Integration tests may only run against a disposable PayBridge test database.');
}

function pbfp_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

function pbfp_assert_same(mixed $expected, mixed $actual, string $message): void
{
    pbfp_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** @param array<string, string> $overrides */
function pbfp_configure(array $overrides = array()): void
{
    update_option(PBFP_TEST_SETTINGS_OPTION, $overrides + array(
        'enabled' => 'yes',
        'title' => 'Pay by Bank',
        'description' => 'Securely pay directly from your bank account.',
        'environment' => 'sandbox',
        'client_id' => 'test-client-id',
        'secret' => 'test-sandbox-secret',
        'funding_account_id' => '',
        'link_customization_name' => 'pbfp_one_account',
        'statement_descriptor' => 'PAYMENT',
        'network' => 'same-day-ach',
        'confirmation_state' => 'funds_available',
        'debug' => 'yes',
        'delete_data_on_uninstall' => 'no',
    ), false);
    update_option('woocommerce_currency', 'USD');
}

/** The Plaid event stream of the configured credentials (environment + account, ADR-0018). */
function pbfp_scope(): \PayBridge\Plaid\Settings\AccountScope
{
    return \PayBridge\Plaid\Settings\Settings::load()->account_scope();
}

function pbfp_order(string $total, int $customer_id = 0, string $currency = 'USD'): WC_Order
{
    $order = wc_create_order(array('customer_id' => $customer_id));
    if (is_wp_error($order)) {
        throw new RuntimeException($order->get_error_message());
    }
    $order->add_product(pbfp_product(), 1, array('subtotal' => $total, 'total' => $total));
    $order->set_currency($currency);
    $order->set_billing_first_name('Anne');
    $order->set_billing_last_name('Charleston');
    $order->set_billing_email('anne@example.com');
    $order->set_payment_method(PBFP_TEST_GATEWAY_ID);
    $order->set_payment_method_title('Pay by Bank');
    $order->set_total($total);
    $order->set_status('pending');
    $order->save();
    return $order;
}

function pbfp_product(): WC_Product
{
    static $product = null;
    if ($product instanceof WC_Product) {
        return $product;
    }
    $existing = get_option('pbfp_test_product_id');
    $found = $existing ? wc_get_product((int) $existing) : null;
    if ($found instanceof WC_Product) {
        return $product = $found;
    }
    $new = new WC_Product_Simple();
    $new->set_name('PayBridge physical test product');
    $new->set_regular_price('10.00');
    $new->set_virtual(false);
    $new->set_status('publish');
    $new->save();
    update_option('pbfp_test_product_id', $new->get_id(), false);
    return $product = $new;
}

/** Runs a WooCommerce admin refund exactly like the order screen does (wc_create_refund → process_refund). */
function pbfp_wc_refund(WC_Order $order, string $amount, string $reason = 'Customer request'): WC_Order_Refund|WP_Error
{
    return wc_create_refund(array('order_id' => $order->get_id(), 'amount' => $amount, 'reason' => $reason, 'refund_payment' => true, 'restock_items' => false));
}

/** @return list<array<string, mixed>> PayBridge refund rows of an order, oldest first. */
function pbfp_refund_rows(WC_Order $order): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paybridge_plaid_refunds WHERE order_id = %d ORDER BY id ASC", $order->get_id()), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** @return array<string, mixed>|null Payment index row. */
function pbfp_index_row(WC_Order $order): ?array
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paybridge_plaid_payment_locks WHERE order_id = %d", $order->get_id()), ARRAY_A);
    return is_array($row) ? $row : null;
}

function pbfp_reload(WC_Order|int $order): WC_Order
{
    $id = $order instanceof WC_Order ? $order->get_id() : $order;
    $fresh = wc_get_order($id);
    pbfp_assert($fresh instanceof WC_Order, 'Order reload failed.');
    return $fresh;
}

function pbfp_gateway(): WC_Payment_Gateway
{
    WC()->payment_gateways()->init();
    $gateways = WC()->payment_gateways()->payment_gateways();
    pbfp_assert(isset($gateways[PBFP_TEST_GATEWAY_ID]), 'PayBridge gateway is not registered.');
    return $gateways[PBFP_TEST_GATEWAY_ID];
}

/** @return list<string> */
function pbfp_notes(WC_Order $order): array
{
    return array_map(static fn ($note): string => (string) $note->content, wc_get_order_notes(array('order_id' => $order->get_id(), 'limit' => 200)));
}

function pbfp_note_count(WC_Order $order, string $needle): int
{
    return count(array_filter(pbfp_notes($order), static fn (string $note): bool => str_contains($note, $needle)));
}

/** @return array{status:int, data:mixed} */
function pbfp_rest(string $route, array $body = array(), array $headers = array(), ?string $raw_body = null): array
{
    $request = new WP_REST_Request('POST', '/paybridge-for-plaid/v1' . $route);
    if (null !== $raw_body) {
        $request->set_body($raw_body);
        $request->set_header('content-type', 'application/json');
    } else {
        $request->set_body_params($body);
    }
    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
    }
    $response = rest_do_request($request);
    return array('status' => $response->get_status(), 'data' => $response->get_data());
}

function pbfp_run_scheduled(string $hook): int
{
    $ids = as_get_scheduled_actions(array('hook' => $hook, 'group' => 'paybridge-for-plaid', 'status' => ActionScheduler_Store::STATUS_PENDING), 'ids');
    foreach ($ids as $id) {
        ActionScheduler::runner()->process_action($id, 'pbfp-test');
    }
    return count($ids);
}

function pbfp_pending_actions(string $hook): int
{
    return count(as_get_scheduled_actions(array('hook' => $hook, 'group' => 'paybridge-for-plaid', 'status' => ActionScheduler_Store::STATUS_PENDING), 'ids'));
}

function pbfp_reset_world(): void
{
    PayBridge_Test_Plaid_Mock::reset();
    as_unschedule_all_actions('paybridge_plaid_transfer_event_sync', array(), 'paybridge-for-plaid');
    delete_option('paybridge_plaid_payment_alerts');
    global $wpdb;
    // Event cursors and event-sync health of every Plaid account scope.
    foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'paybridge\\_plaid\\_event\\_%'") as $name) {
        delete_option((string) $name);
    }
    $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'paybridge_plaid_events');
    $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'paybridge_plaid_refunds');
    foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pbfp\\_%'") as $name) {
        delete_transient(substr((string) $name, strlen('_transient_')));
    }
}
