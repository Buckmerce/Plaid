<?php

/**
 * End-to-end payment flow against the Plaid double (tests/fixtures/plaid-mock.php):
 * Sandbox scenarios, idempotency matrix, access control and recovery paths.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Checkout\PaymentAccess;
use PayBridge\Plaid\Checkout\PaymentPage;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\Payment\CompletionResult;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Persistence\PaymentLockStore;

global $wpdb;
pbfp_configure();
pbfp_reset_world();
$mock = PayBridge_Test_Plaid_Mock::class;

/** Fresh container per step mirrors separate PHP requests. */
function pbfp_c(): Container
{
    return new Container();
}

function pbfp_checkout(WC_Order $order): array
{
    return pbfp_gateway()->process_payment($order->get_id());
}

function pbfp_token(WC_Order $order): string
{
    $token = pbfp_c()->attempts()->issue_link_token(pbfp_reload($order));
    pbfp_assert(null !== $token, 'Expected a Link token.');
    return $token->token;
}

function pbfp_webhook(string $environment = 'sandbox'): array
{
    $body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => $environment));
    return pbfp_rest('/webhook', array(), array('plaid-verification' => PayBridge_Test_Plaid_Mock::sign($body)), $body);
}

function pbfp_sync_events(): array
{
    return pbfp_c()->event_sync()->run();
}

function pbfp_meta(WC_Order $order, string $key): string
{
    return (string) pbfp_reload($order)->get_meta($key, true);
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $11.11: pending → posted → settled → funds_available');
$order = pbfp_order('11.11');
$result = pbfp_checkout($order);
pbfp_assert_same('success', $result['result'], 'process_payment must succeed.');
pbfp_assert(str_contains($result['redirect'], 'order-pay') && str_contains($result['redirect'], 'key=' . $order->get_order_key()), 'Redirect must be the protected order-pay page.');
$creates = $mock::calls('/transfer/intent/create');
pbfp_assert_same(1, count($creates), 'Exactly one Transfer Intent create.');
$request = $creates[0]['body'];
pbfp_assert_same('PAYMENT', $request['mode'], 'Intent mode.');
pbfp_assert_same('11.11', $request['amount'], 'Intent amount comes from the order.');
pbfp_assert_same('USD', $request['iso_currency_code'], 'Intent currency.');
pbfp_assert_same('web', $request['ach_class'], 'ACH class.');
pbfp_assert(! array_key_exists('funding_account_id', $request), 'Funding account must be omitted for Plaid Ledger.');
pbfp_assert_same((string) $order->get_id(), $request['metadata']['pbfp_order_id'], 'Intent metadata binds the order.');
pbfp_assert(strlen($request['description']) <= 15, 'Description length.');
$intent_id = pbfp_meta($order, OrderMeta::TRANSFER_INTENT_ID);
pbfp_assert('' !== $intent_id, 'Intent ID persisted.');
pbfp_assert_same(PaymentState::INTENT_CREATED, pbfp_meta($order, OrderMeta::PAYMENT_STATE), 'State after checkout.');
pbfp_assert_same('pending', pbfp_reload($order)->get_status(), 'Order stays pending until the transfer exists.');
$snapshot = json_decode(pbfp_meta($order, OrderMeta::PAYMENT_SNAPSHOT), true);
pbfp_assert_same('11.11', $snapshot['amount'], 'Snapshot amount.');
pbfp_assert_same('sandbox', $snapshot['environment'], 'Snapshot environment.');
$lock = ( new PaymentLockStore() )->row($order->get_id());
pbfp_assert('created' === $lock['status'] && $intent_id === $lock['transfer_intent_id'], 'Durable reservation records the intent.');

// Idempotency: repeated process_payment and payment page refresh reuse the intent.
pbfp_checkout($order);
pbfp_checkout($order);
pbfp_assert_same(1, count($mock::calls('/transfer/intent/create')), 'Repeated process_payment must not create another intent.');
pbfp_assert_same($intent_id, pbfp_meta($order, OrderMeta::TRANSFER_INTENT_ID), 'Intent is reused.');

// Link token bound to the stored intent; opening Link again reuses the same intent.
$token = pbfp_token($order);
$second_token = pbfp_token($order);
$link_calls = $mock::calls('/link/token/create');
pbfp_assert_same(2, count($link_calls), 'Two Link tokens for two opens.');
foreach ($link_calls as $call) {
    pbfp_assert_same(array('intent_id' => $intent_id), $call['body']['transfer'], 'Link token must be bound to the stored intent.');
    pbfp_assert(! isset($call['body']['amount']), 'Link token request must not carry an amount.');
}
pbfp_assert_same(PaymentState::INTENT_PENDING, pbfp_meta($order, OrderMeta::PAYMENT_STATE), 'State after Link token.');
pbfp_assert('' !== pbfp_meta($order, OrderMeta::LINK_TOKEN_EXPIRES_AT), 'Authorization window recorded.');
pbfp_assert(PaymentAttemptService::authorization_window_open(pbfp_reload($order)), 'Authorization window is open.');

// Completion before the customer authorized is not success.
$completion = pbfp_c()->completion()->complete(pbfp_reload($order));
pbfp_assert_same(CompletionResult::INCOMPLETE, $completion->status, 'Completion without authorization.');
pbfp_assert(! pbfp_reload($order)->is_paid(), 'Browser signal alone never pays the order.');

// Customer completes Transfer UI; the server verifies with /transfer/intent/get.
$transfer_id = $mock::authorize($second_token);
$completion = pbfp_c()->completion()->complete(pbfp_reload($order));
pbfp_assert_same(CompletionResult::SUBMITTED, $completion->status, 'Completion after authorization.');
pbfp_assert_same($transfer_id, pbfp_meta($order, OrderMeta::TRANSFER_ID), 'Transfer ID persisted from Plaid.');
pbfp_assert_same('on-hold', pbfp_reload($order)->get_status(), 'New ACH transfer → on-hold.');
pbfp_assert(! pbfp_reload($order)->is_paid(), 'Pending ACH is not paid.');
pbfp_assert_same($transfer_id, pbfp_reload($order)->get_transaction_id(), 'WooCommerce transaction ID is the Plaid transfer ID.');
pbfp_c()->completion()->complete(pbfp_reload($order));
pbfp_assert_same(1, pbfp_note_count(pbfp_reload($order), 'Plaid transfer created'), 'Repeated completion is idempotent.');
pbfp_assert_same(0, count(array_filter($mock::calls('/link/token/create'), static fn (array $c): bool => ($c['body']['transfer']['intent_id'] ?? '') !== $intent_id)), 'No token for other intents.');

// Plaid lifecycle via verified webhook → event sync.
$mock::advance($transfer_id);
$response = pbfp_webhook();
pbfp_assert_same(200, $response['status'], 'Verified webhook accepted.');
pbfp_assert_same(1, pbfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Webhook enqueues event sync.');
pbfp_webhook();
pbfp_assert_same(1, pbfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Duplicate webhook coalesces into one job.');
pbfp_assert(! pbfp_reload($order)->is_paid(), 'The webhook itself never changes the order.');
pbfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK);
$paid = pbfp_reload($order);
pbfp_assert_same(PaymentState::FUNDS_AVAILABLE, $paid->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after events.');
pbfp_assert($paid->is_paid() && 'processing' === $paid->get_status(), 'Funds available → paid (processing).');
pbfp_assert_same('4', $paid->get_meta(OrderMeta::LAST_EVENT_ID, true), 'Last event ID recorded.');
$paid_at = $paid->get_date_paid()?->getTimestamp();
pbfp_assert_same('4', get_option('paybridge_plaid_event_cursor_sandbox'), 'Cursor advanced to highest event.');

// Same events again: harmless.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_events SET status = 'received', attempts = 0 WHERE transfer_id = %s", $transfer_id));
pbfp_sync_events();
$again = pbfp_reload($order);
pbfp_assert_same($paid_at, $again->get_date_paid()?->getTimestamp(), 'Replayed events must not re-complete payment.');
pbfp_assert_same(1, pbfp_note_count($again, 'funds available'), 'Replayed events add no notes.');
pbfp_assert_same(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paybridge_plaid_events WHERE status NOT IN ('processed','ignored')"), 'All events processed.');
pbfp_assert_same(4, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paybridge_plaid_events"), 'Unique event identity: no duplicates stored.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $22.22: pending → failed, then a safe retry');
$failed = pbfp_order('22.22');
pbfp_checkout($failed);
$failed_token = pbfp_token($failed);
$failed_transfer = $mock::authorize($failed_token);
pbfp_c()->completion()->complete(pbfp_reload($failed));
$mock::advance($failed_transfer);
pbfp_sync_events();
$failed = pbfp_reload($failed);
pbfp_assert_same(PaymentState::FAILED, $failed->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after failure.');
pbfp_assert_same('failed', $failed->get_status(), 'Failed transfer → failed order.');
pbfp_assert(! $failed->is_paid() && null === $failed->get_date_paid(), 'A failed payment is never paid.');
$creates_before = count($mock::calls('/transfer/intent/create'));
pbfp_checkout($failed);
pbfp_assert_same($creates_before + 1, count($mock::calls('/transfer/intent/create')), 'Retry after failure creates exactly one new intent.');
$retired = pbfp_reload($failed)->get_meta(OrderMeta::RETIRED_ATTEMPTS, true);
pbfp_assert(is_array($retired) && 1 === count($retired) && $failed_transfer === $retired[0]['transfer_id'], 'Failed attempt archived.');
$mock::add_event($failed_transfer, 'posted');
pbfp_sync_events();
pbfp_assert_same(PaymentState::INTENT_CREATED, pbfp_meta($failed, OrderMeta::PAYMENT_STATE), 'Late event of a retired attempt cannot touch the new attempt.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $33.33: funds available, then ACH return R01');
delete_option('pbfp_test_mails');
$returned = pbfp_order('33.33');
$returned_hooks = 0;
add_action('paybridge_plaid_payment_returned', static function () use (&$returned_hooks): void {
    ++$returned_hooks;
});
pbfp_checkout($returned);
$returned_transfer = $mock::authorize(pbfp_token($returned));
pbfp_c()->completion()->complete(pbfp_reload($returned));
$mock::advance($returned_transfer);
pbfp_sync_events();
$returned = pbfp_reload($returned);
pbfp_assert_same(PaymentState::RETURNED, $returned->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after return.');
pbfp_assert_same('R01', $returned->get_meta(OrderMeta::RETURN_CODE, true), 'Return code stored.');
pbfp_assert_same('failed', $returned->get_status(), 'Returned ACH → order failed.');
pbfp_assert(! $returned->is_paid(), 'Returned payment is no longer paid.');
pbfp_assert(null !== $returned->get_date_paid(), 'Original payment history is preserved.');
pbfp_assert(1 === pbfp_note_count($returned, 'ACH RETURN') && 1 === pbfp_note_count($returned, 'R01'), 'Private return note with reason.');
$alerts = get_option('paybridge_plaid_payment_alerts');
pbfp_assert(is_array($alerts) && isset($alerts[$returned->get_id() . ':returned']), 'Admin alert recorded.');
$mails = get_option('pbfp_test_mails');
pbfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $m): bool => str_contains((string) $m['subject'], 'ACH return'))), 'Merchant emailed once.');
pbfp_assert_same(1, $returned_hooks, 'Return hook fired once.');
$mock::add_event($returned_transfer, 'funds_available');
pbfp_sync_events();
pbfp_assert_same(PaymentState::RETURNED, pbfp_meta($returned, OrderMeta::PAYMENT_STATE), 'Late success event never overrides a return.');
pbfp_assert(! pbfp_reload($returned)->is_paid(), 'Returned order stays unpaid.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Out-of-order events');
$ooo = pbfp_order('11.11');
pbfp_checkout($ooo);
$ooo_transfer = $mock::authorize(pbfp_token($ooo));
pbfp_c()->completion()->complete(pbfp_reload($ooo));
$mock::add_event($ooo_transfer, 'settled');
$mock::add_event($ooo_transfer, 'posted');
pbfp_sync_events();
pbfp_assert_same(PaymentState::SETTLED, pbfp_meta($ooo, OrderMeta::PAYMENT_STATE), 'Posted after settled must not regress.');
pbfp_assert_same('on-hold', pbfp_reload($ooo)->get_status(), 'Settled with funds_available policy stays on-hold.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Declined (NSF) and failed authorizations');
$nsf = pbfp_order('41.00');
pbfp_checkout($nsf);
$nsf_intent = pbfp_meta($nsf, OrderMeta::TRANSFER_INTENT_ID);
$mock::authorize(pbfp_token($nsf), 'nsf');
$result = pbfp_c()->completion()->complete(pbfp_reload($nsf));
pbfp_assert(CompletionResult::INCOMPLETE === $result->status && 'NSF' === $result->reason_code, 'NSF decline is incomplete and retryable.');
pbfp_checkout($nsf);
pbfp_assert_same($nsf_intent, pbfp_meta($nsf, OrderMeta::TRANSFER_INTENT_ID), 'NSF retry reuses the pending intent.');
$declined = pbfp_order('42.00');
pbfp_checkout($declined);
$declined_intent = pbfp_meta($declined, OrderMeta::TRANSFER_INTENT_ID);
$mock::authorize(pbfp_token($declined), 'failed');
pbfp_assert_same(CompletionResult::FAILED, pbfp_c()->completion()->complete(pbfp_reload($declined))->status, 'Failed intent reported.');
pbfp_assert_same(PaymentState::INTENT_FAILED, pbfp_meta($declined, OrderMeta::PAYMENT_STATE), 'Intent failed state.');
pbfp_token($declined);
pbfp_assert($declined_intent !== pbfp_meta($declined, OrderMeta::TRANSFER_INTENT_ID), 'A failed intent is replaced before a new Link token.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Ambiguous create outcomes (timeout, 5xx, response lost after creation)');
$orphans = array();
foreach (array('timeout_after_create', 'server_error', 'timeout') as $mode) {
    $ambiguous = pbfp_order('12.00');
    $known = array_keys($mock::state()['intents']);
    $mock::fail_next('/transfer/intent/create', $mode);
    $result = pbfp_checkout($ambiguous);
    pbfp_assert_same('failure', $result['result'], 'Ambiguous create fails checkout (' . $mode . ').');
    pbfp_assert_same(PaymentState::INTENT_UNCERTAIN, pbfp_meta($ambiguous, OrderMeta::PAYMENT_STATE), 'Ambiguous create → uncertain (' . $mode . ').');
    pbfp_assert_same('', pbfp_meta($ambiguous, OrderMeta::TRANSFER_INTENT_ID), 'No intent ID was stored (' . $mode . ').');
    pbfp_assert_same('uncertain', ( new PaymentLockStore() )->row($ambiguous->get_id())['status'], 'Reservation uncertain (' . $mode . ').');
    // Intents Plaid created but PayBridge never learned about (response lost).
    $orphans = array_merge($orphans, array_diff(array_keys($mock::state()['intents']), $known));
    $result = pbfp_checkout($ambiguous);
    pbfp_assert_same('success', $result['result'], 'Retry succeeds (' . $mode . ').');
    $new_intent = pbfp_meta($ambiguous, OrderMeta::TRANSFER_INTENT_ID);
    pbfp_assert(! in_array($new_intent, $orphans, true), 'Retry uses a new intent (' . $mode . ').');
    pbfp_token($ambiguous);
}
pbfp_assert(count($orphans) >= 1, 'The response-lost scenario created a remote orphan intent.');
$tokenized = array_map(static fn (array $call): string => (string) $call['body']['transfer']['intent_id'], $mock::calls('/link/token/create'));
pbfp_assert(array() === array_intersect($orphans, $tokenized), 'An intent whose create response was lost can never receive a Link token, so it can never move money.');

WP_CLI::log('Definitive rejection and local persistence failure after remote creation');
$rejected = pbfp_order('13.00');
$mock::fail_next('/transfer/intent/create', 'reject');
pbfp_assert_same('failure', pbfp_checkout($rejected)['result'], 'Definitive rejection fails checkout.');
pbfp_assert_same(PaymentState::INTENT_FAILED, pbfp_meta($rejected, OrderMeta::PAYMENT_STATE), 'Rejected create → intent_failed.');
pbfp_assert_same('success', pbfp_checkout($rejected)['result'], 'Retry after rejection succeeds.');

$lost = pbfp_order('14.00');
$fail_save = static function (WC_Order $order): void {
    if ('' !== (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true) && PaymentState::INTENT_CREATING === (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true)) {
        throw new RuntimeException('Simulated database failure while saving the intent ID.');
    }
};
add_action('woocommerce_before_order_object_save', $fail_save);
$creates_before = count($mock::calls('/transfer/intent/create'));
pbfp_assert_same('failure', pbfp_checkout($lost)['result'], 'Local persistence failure fails checkout.');
remove_action('woocommerce_before_order_object_save', $fail_save);
pbfp_assert_same('', pbfp_meta($lost, OrderMeta::TRANSFER_INTENT_ID), 'Intent ID not on the order yet.');
$lock = ( new PaymentLockStore() )->row($lost->get_id());
pbfp_assert('created' === $lock['status'] && '' !== $lock['transfer_intent_id'], 'Intent ID durable in the reservation.');
pbfp_assert_same('success', pbfp_checkout($lost)['result'], 'Retry recovers.');
pbfp_assert_same($creates_before + 1, count($mock::calls('/transfer/intent/create')), 'Recovery adopts the saved intent instead of creating another.');
pbfp_assert_same($lock['transfer_intent_id'], pbfp_meta($lost, OrderMeta::TRANSFER_INTENT_ID), 'Adopted intent ID.');

WP_CLI::log('Lock contention, DB failure and expired leases fail closed');
$busy = pbfp_order('15.00');
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lock_name = 'pbfp_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':paybridge-plaid:payment:' . $busy->get_id()), 0, 48);
pbfp_assert_same('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)), 'Second connection holds the payment lock.');
$creates_before = count($mock::calls('/transfer/intent/create'));
pbfp_assert_same('failure', pbfp_checkout($busy)['result'], 'Concurrent worker → busy.');
pbfp_assert_same($creates_before, count($mock::calls('/transfer/intent/create')), 'No Plaid call while another worker holds the lock.');
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
$other->close();

$dbfail = pbfp_order('16.00');
$locks_table = Installer::locks_table();
$wpdb->query("RENAME TABLE {$locks_table} TO {$locks_table}_offline");
$suppress = $wpdb->suppress_errors(true);
$result = pbfp_checkout($dbfail);
$wpdb->suppress_errors($suppress);
$wpdb->query("RENAME TABLE {$locks_table}_offline TO {$locks_table}");
pbfp_assert_same('failure', $result['result'], 'Reservation DB failure fails checkout.');
pbfp_assert_same($creates_before, count($mock::calls('/transfer/intent/create')), 'No remote payment without a durable reservation.');

$crashed = pbfp_order('17.00');
$wpdb->insert($locks_table, array('order_id' => $crashed->get_id(), 'environment' => 'sandbox', 'status' => 'creating', 'owner_token' => str_repeat('a', 64), 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 600), 'attempts' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')));
$crashed->update_meta_data(OrderMeta::PAYMENT_STATE, PaymentState::INTENT_CREATING);
$crashed->save();
pbfp_assert_same('failure', pbfp_checkout($crashed)['result'], 'A live creating lease blocks a second worker.');
$wpdb->update($locks_table, array('lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 5)), array('order_id' => $crashed->get_id()));
pbfp_assert_same('success', pbfp_checkout($crashed)['result'], 'An expired lease from a crashed worker is recovered.');
pbfp_assert(1 === pbfp_note_count(pbfp_reload($crashed), 'unknown outcome'), 'Abandoned attempt recorded as uncertain.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Access control: order key, ownership and REST authorization');
$customer = wp_insert_user(array('user_login' => 'pbfp_customer_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'c' . wp_rand() . '@example.com'));
$intruder = wp_insert_user(array('user_login' => 'pbfp_intruder_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'i' . wp_rand() . '@example.com'));
$owned = pbfp_order('18.00', (int) $customer);
$access = new PaymentAccess();
wp_set_current_user((int) $customer);
pbfp_assert($access->can_access($owned, $owned->get_order_key()), 'Owner with key may pay.');
pbfp_assert(! $access->can_access($owned, 'wc_order_wrongkey'), 'Wrong key denied.');
pbfp_assert(! $access->can_access($owned, ''), 'Missing key denied.');
wp_set_current_user((int) $intruder);
pbfp_assert(! $access->can_access($owned, $owned->get_order_key()), 'Another customer is denied even with the key.');
wp_set_current_user(0);
pbfp_assert(! $access->can_access($owned, $owned->get_order_key()), 'Logged-out visitor is denied for a registered order.');
$guest = pbfp_order('19.00');
pbfp_assert(! $access->can_access($guest, $guest->get_order_key()), 'Guest order without a session grant is denied.');
$access->grant($guest);
pbfp_assert($access->can_access($guest, $guest->get_order_key()), 'Guest with a session grant may pay.');
pbfp_assert(! $access->can_access($guest, substr($guest->get_order_key(), 0, -1) . 'x'), 'Guest with the wrong key is denied.');

wp_set_current_user((int) $customer);
pbfp_checkout($owned);
$nonce = wp_create_nonce(PaymentAccess::nonce_action($owned->get_id()));
$ok = pbfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => $nonce));
pbfp_assert(200 === $ok['status'] && 'ready' === $ok['data']['status'] && str_starts_with((string) $ok['data']['link_token'], 'link-sandbox-'), 'Authorized REST Link token.');
pbfp_assert(! isset($ok['data']['amount']) && ! str_contains((string) wp_json_encode($ok['data']), 'test-sandbox-secret'), 'Link token response leaks nothing sensitive.');
$bad_key = pbfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => 'wc_order_forged', 'payment_nonce' => $nonce));
pbfp_assert_same(403, $bad_key['status'], 'Forged key rejected.');
$bad_nonce = pbfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => 'deadbeef00'));
pbfp_assert_same(403, $bad_nonce['status'], 'Invalid nonce rejected.');
$missing = pbfp_rest('/link-token', array('order_id' => $owned->get_id()));
pbfp_assert_same(400, $missing['status'], 'Schema requires order key and nonce.');
// A different browser session: no grant for the guest order, and another user's order.
WC()->session->set('pbfp_payment_grants', array());
$other_order = pbfp_rest('/complete', array('order_id' => $guest->get_id(), 'order_key' => $guest->get_order_key(), 'payment_nonce' => wp_create_nonce(PaymentAccess::nonce_action($guest->get_id()))));
pbfp_assert_same(403, $other_order['status'], 'Another session cannot complete a guest order even with its key (IDOR).');
$intruder_order = pbfp_order('18.50', (int) $intruder);
$foreign = pbfp_rest('/link-token', array('order_id' => $intruder_order->get_id(), 'order_key' => $intruder_order->get_order_key(), 'payment_nonce' => wp_create_nonce(PaymentAccess::nonce_action($intruder_order->get_id()))));
pbfp_assert_same(403, $foreign['status'], 'A customer cannot request a Link token for another customer order (IDOR).');
$forged_success = pbfp_rest('/complete', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => $nonce, 'status' => 'SUCCEEDED', 'transfer_id' => 'forged', 'amount' => '0.01'));
pbfp_assert(200 === $forged_success['status'] && CompletionResult::INCOMPLETE === $forged_success['data']['status'], 'Browser-submitted success/transfer/amount are ignored.');
pbfp_assert('' === pbfp_meta($owned, OrderMeta::TRANSFER_ID) && ! pbfp_reload($owned)->is_paid(), 'Forged completion did not mutate the order.');
wp_set_current_user(0);

// ---------------------------------------------------------------------------------
WP_CLI::log('Cancelled order, amount mismatch, lost webhook reconciliation, adoption');
$cancel = pbfp_order('20.00');
pbfp_checkout($cancel);
$cancel_token = pbfp_token($cancel);
pbfp_assert(false === ( new PaymentPage() )->keep_order_during_authorization(true, pbfp_reload($cancel)), 'Unpaid-order auto-cancel is suppressed while Link can be used.');
pbfp_reload($cancel)->update_status('cancelled');
$cancel_transfer = $mock::authorize($cancel_token);
$mock::advance($cancel_transfer);
pbfp_c()->completion()->complete(pbfp_reload($cancel));
pbfp_sync_events();
$cancel = pbfp_reload($cancel);
pbfp_assert_same(PaymentState::MANUAL_REVIEW, $cancel->get_meta(OrderMeta::PAYMENT_STATE, true), 'Money for a cancelled order → manual review.');
pbfp_assert_same('payment_for_cancelled_order', $cancel->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true), 'Manual review reason.');
pbfp_assert(! $cancel->is_paid(), 'Cancelled order is not auto-fulfilled.');

$mismatch = pbfp_order('21.00');
pbfp_checkout($mismatch);
$mismatch_transfer = $mock::authorize(pbfp_token($mismatch), 'success', '0.01');
pbfp_c()->completion()->complete(pbfp_reload($mismatch));
pbfp_assert_same(PaymentState::MANUAL_REVIEW, pbfp_meta($mismatch, OrderMeta::PAYMENT_STATE), 'Wrong transfer amount → manual review.');
$mock::advance($mismatch_transfer);
pbfp_sync_events();
pbfp_assert(! pbfp_reload($mismatch)->is_paid(), 'A wrong amount is never accepted as payment.');

$lost_hook = pbfp_order('11.11');
pbfp_checkout($lost_hook);
$lost_transfer = $mock::authorize(pbfp_token($lost_hook));
// The customer closed the page: no completion call and no webhook.
$mock::advance($lost_transfer);
$stale = pbfp_reload($lost_hook);
$stale->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c', time() - 3600));
$stale->save();
$report = pbfp_c()->reconciliation()->run();
pbfp_assert_same('ok', $report['status'], 'Reconciliation run.');
$lost_hook = pbfp_reload($lost_hook);
pbfp_assert_same($lost_transfer, $lost_hook->get_meta(OrderMeta::TRANSFER_ID, true), 'Reconciliation/event adoption bound the transfer.');
pbfp_assert(PaymentState::FUNDS_AVAILABLE === $lost_hook->get_meta(OrderMeta::PAYMENT_STATE, true) && $lost_hook->is_paid(), 'Lost webhook recovered by reconciliation.');
pbfp_assert('' !== (string) get_option('paybridge_plaid_last_reconciliation'), 'Reconciliation timestamp recorded.');

$manual = pbfp_order('11.11');
pbfp_checkout($manual);
$manual_transfer = $mock::authorize(pbfp_token($manual));
pbfp_c()->synchronizer()->sync(pbfp_reload($manual));
pbfp_assert_same($manual_transfer, pbfp_meta($manual, OrderMeta::TRANSFER_ID), 'Manual Sync with Plaid binds the transfer.');
pbfp_assert_same('on-hold', pbfp_reload($manual)->get_status(), 'Manual sync projects on-hold.');

WP_CLI::success('PayBridge payment flow suite passed (HPOS=' . (getenv('PAYBRIDGE_PLAID_EXPECT_HPOS') ?: '?') . ').');
