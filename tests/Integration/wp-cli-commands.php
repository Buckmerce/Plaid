<?php

/**
 * Operational WP-CLI commands (wp buckmerce-plaid …), each run as its own WP-CLI process the way an
 * operator runs it: success paths, invalid arguments, missing credentials, Plaid errors and an
 * unreachable Plaid. Every failure must end with exit status 1 and a one-line "Error:" — never with
 * an uncaught exception (a PHP fatal and a stack trace in the log) — and no output may contain the
 * Plaid secret.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

global $wpdb;
bmfp_configure();
bmfp_reset_world();
$mock = Buckmerce_Test_Plaid_Mock::class;
$secret = 'test-sandbox-secret';
// A public IP literal: WordPress validates it without a DNS lookup, and the request itself never
// leaves the Plaid double.
$public_host = '93.184.216.34';

$cli = static function (string $command) use ($secret): object {
    $result = WP_CLI::runcommand('buckmerce-plaid ' . $command, array('return' => 'all', 'exit_error' => false));
    $output = $result->stdout . "\n" . $result->stderr;
    bmfp_assert(! str_contains($output, $secret), 'CLI output must never contain the Plaid secret: ' . $command);
    foreach (array('Fatal error', 'Uncaught', 'Stack trace') as $crash) {
        bmfp_assert(! str_contains($output, $crash), 'A command must not end with an uncaught exception: ' . $command . ' → ' . substr($output, 0, 400));
    }
    return $result;
};
$succeeds = static function (string $command, string $expected) use ($cli): void {
    $result = $cli($command);
    bmfp_assert(0 === $result->return_code && str_contains($result->stdout, $expected), 'Expected exit 0 and "' . $expected . '" from ' . $command . ', got ' . $result->return_code . ': ' . substr($result->stdout . $result->stderr, 0, 400));
};
$fails = static function (string $command, string $expected) use ($cli): void {
    $result = $cli($command);
    bmfp_assert(1 === $result->return_code && str_starts_with(ltrim($result->stderr), 'Error: ') && str_contains($result->stderr, $expected), 'Expected exit 1 and "Error: … ' . $expected . '" from ' . $command . ', got ' . $result->return_code . ': ' . substr($result->stdout . $result->stderr, 0, 400));
};

WP_CLI::log('Registration: wp buckmerce-plaid and its subcommands');
$commands = WP_CLI::get_root_command()->get_subcommands();
bmfp_assert(isset($commands['buckmerce-plaid']), 'wp buckmerce-plaid is registered.');
$subcommands = array_keys($commands['buckmerce-plaid']->get_subcommands());
sort($subcommands);
bmfp_assert_same(array('fire-sandbox-webhook', 'reconcile', 'refunds', 'simulate-refund', 'status', 'sync-events', 'sync-order', 'test-connection'), $subcommands, 'Exactly the documented subcommands.');
bmfp_assert_same(array('buckmerce-plaid'), array_values(array_filter(array_keys($commands), static fn (string $name): bool => str_contains($name, 'plaid'))), 'One Plaid command namespace.');

WP_CLI::log('Success paths');
$succeeds('status', 'Plugin version: ' . BUCKMERCE_PLAID_VERSION);
$succeeds('test-connection', 'Success: ');
$succeeds('sync-events', 'Success: Event sync finished.');
$succeeds('reconcile', 'Success: Reconciliation finished.');
$order = bmfp_order('11.11');
bmfp_assert_same('success', bmfp_gateway()->process_payment($order->get_id())['result'], 'A payment attempt exists for the order commands.');
$succeeds('sync-order ' . $order->get_id(), 'Success: Order ' . $order->get_id() . ': payment state ');
$succeeds('refunds ' . $order->get_id(), 'Refunded: $');
$succeeds('fire-sandbox-webhook --webhook-url=https://' . $public_host . '/wp-json/buckmerce-for-plaid/v1/webhook', 'Success: Plaid accepted the request (request_id ');
bmfp_assert_same('https://' . $public_host . '/wp-json/buckmerce-for-plaid/v1/webhook', $mock::calls('/sandbox/transfer/fire_webhook')[0]['body']['webhook'] ?? null, 'The webhook URL reaches Plaid unchanged.');
// "--url" is a global WP-CLI parameter: it never reaches the command, which then uses this site's own (HTTP) endpoint.
$fails('fire-sandbox-webhook --url=https://' . $public_host . '/webhook', 'must be a public HTTPS URL');

WP_CLI::log('Invalid arguments end with exit status 1');
$fails('sync-order 999999999', 'Not a Buckmerce order.');
$fails('sync-order not-a-number', 'Not a Buckmerce order.');
$fails('refunds 999999999', 'Not a Buckmerce order.');
$fails('simulate-refund refund-1 refund.exploded', 'Usage: wp buckmerce-plaid simulate-refund');
$fails('simulate-refund refund_1! refund.posted', 'Usage: wp buckmerce-plaid simulate-refund');
$fails('fire-sandbox-webhook --webhook-url=http://' . $public_host . '/webhook', 'must be a public HTTPS URL');
$fails('fire-sandbox-webhook --webhook-url=https://127.0.0.1/webhook', 'must be a public HTTPS URL');
$fails('fire-sandbox-webhook --webhook-url=https://192.168.1.10/webhook', 'must be a public HTTPS URL');
$missing = $cli('sync-order');
bmfp_assert(0 !== $missing->return_code, 'A missing required argument is refused.');
$unknown = $cli('no-such-command');
bmfp_assert(0 !== $unknown->return_code, 'An unknown subcommand is refused.');

WP_CLI::log('Plaid errors and an unreachable Plaid end with exit status 1, not with a crash');
$fails('simulate-refund refund-1 refund.posted', 'Plaid API error INVALID_REQUEST/INVALID_FIELD (HTTP 400)');
$mock::fail_next('/sandbox/transfer/refund/simulate', 'timeout');
$fails('simulate-refund refund-1 refund.posted', 'Plaid could not be reached');
$mock::fail_next('/sandbox/transfer/fire_webhook', 'reject');
$fails('fire-sandbox-webhook --webhook-url=https://' . $public_host . '/webhook', 'Plaid API error INVALID_REQUEST/INVALID_FIELD (HTTP 400)');
$mock::fail_next('/sandbox/transfer/fire_webhook', 'server_error');
$fails('fire-sandbox-webhook --webhook-url=https://' . $public_host . '/webhook', 'Plaid API error API_ERROR/INTERNAL_SERVER_ERROR (HTTP 500)');
$mock::fail_next('/transfer/intent/get', 'reject');
$fails('sync-order ' . $order->get_id(), 'Plaid API error INVALID_REQUEST/INVALID_FIELD (HTTP 400)');
$mock::fail_next('/transfer/intent/get', 'timeout');
$fails('sync-order ' . $order->get_id(), 'Plaid could not be reached');
$mock::fail_next('/transfer/event/sync', 'reject');
$fails('sync-events', 'Event sync failed');
// A reconciliation pass continues after a failed event sync; the command still must not report success.
$mock::fail_next('/transfer/event/sync', 'timeout');
$fails('reconcile', 'its Plaid event sync failed');
$succeeds('reconcile', 'Success: Reconciliation finished.');
$mock::fail_next('/transfer/configuration/get', 'reject');
$fails('test-connection', 'INVALID_FIELD');
// A failed command changed nothing: the order still syncs afterwards.
$succeeds('sync-order ' . $order->get_id(), 'Success: Order ' . $order->get_id() . ': payment state ');

WP_CLI::log('Sandbox-only commands are refused in Production');
bmfp_configure(array('environment' => 'production', 'client_id' => 'production-client-id', 'secret' => $secret));
$before = count($mock::calls());
$fails('simulate-refund refund-1 refund.posted', 'only available in the Sandbox environment');
$fails('fire-sandbox-webhook --webhook-url=https://' . $public_host . '/webhook', 'only be fired in the Sandbox environment');
bmfp_assert_same($before, count($mock::calls()), 'No Sandbox endpoint is called in Production.');

WP_CLI::log('Without credentials every command explains itself');
bmfp_configure(array('client_id' => '', 'secret' => ''));
$succeeds('status', 'Plugin version: ' . BUCKMERCE_PLAID_VERSION);
$before = count($mock::calls());
foreach (array('test-connection', 'sync-events', 'reconcile', 'sync-order ' . $order->get_id(), 'simulate-refund refund-1 refund.posted', 'fire-sandbox-webhook --webhook-url=https://' . $public_host . '/webhook') as $command) {
    $result = $cli($command);
    bmfp_assert(1 === $result->return_code && str_starts_with(ltrim($result->stderr), 'Error: '), 'Exit status 1 without credentials: ' . $command . ' → ' . $result->return_code . ': ' . substr($result->stdout . $result->stderr, 0, 300));
}
$succeeds('refunds ' . $order->get_id(), 'Refunded: $');
bmfp_assert_same($before, count($mock::calls()), 'Nothing is sent to Plaid without credentials.');

// Leave the store as the next suite expects it: configured, no payment of this suite monitored.
bmfp_configure();
$wpdb->delete($wpdb->prefix . 'buckmerce_plaid_payment_locks', array('order_id' => $order->get_id()));
$order->delete(true);
bmfp_reset_world();
WP_CLI::success('Buckmerce WP-CLI command suite passed (HPOS=' . ('yes' === getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ? 'yes' : 'no') . ').');
