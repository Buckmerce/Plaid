<?php

/**
 * End-to-end payment flow against the Plaid double (tests/fixtures/plaid-mock.php):
 * Sandbox scenarios, idempotency matrix, access control and recovery paths.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Checkout\PaymentAccess;
use Buckmerce\Plaid\Checkout\PaymentPage;
use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Payment\CompletionResult;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentLockStore;

global $wpdb;
bmfp_configure();
bmfp_reset_world();
$mock = Buckmerce_Test_Plaid_Mock::class;

/** Fresh container per step mirrors separate PHP requests. */
function bmfp_c(): Container
{
    return new Container();
}

function bmfp_checkout(WC_Order $order): array
{
    return bmfp_gateway()->process_payment($order->get_id());
}

function bmfp_token(WC_Order $order): string
{
    $token = bmfp_c()->attempts()->issue_link_token(bmfp_reload($order));
    bmfp_assert(null !== $token, 'Expected a Link token.');
    return $token->token;
}

function bmfp_webhook(string $environment = 'sandbox'): array
{
    $body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => $environment));
    return bmfp_rest('/webhook', array(), array('plaid-verification' => Buckmerce_Test_Plaid_Mock::sign($body)), $body);
}

function bmfp_sync_events(): array
{
    return bmfp_c()->event_sync()->run();
}

function bmfp_meta(WC_Order $order, string $key): string
{
    return (string) bmfp_reload($order)->get_meta($key, true);
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $11.11: pending → posted → settled → funds_available');
$order = bmfp_order('11.11');
$result = bmfp_checkout($order);
bmfp_assert_same('success', $result['result'], 'process_payment must succeed.');
bmfp_assert(str_contains($result['redirect'], 'order-pay') && str_contains($result['redirect'], 'key=' . $order->get_order_key()), 'Redirect must be the protected order-pay page.');
$creates = $mock::calls('/transfer/intent/create');
bmfp_assert_same(1, count($creates), 'Exactly one Transfer Intent create.');
$request = $creates[0]['body'];
bmfp_assert_same('PAYMENT', $request['mode'], 'Intent mode.');
bmfp_assert_same('11.11', $request['amount'], 'Intent amount comes from the order.');
bmfp_assert_same('USD', $request['iso_currency_code'], 'Intent currency.');
bmfp_assert_same('web', $request['ach_class'], 'ACH class.');
bmfp_assert(! array_key_exists('funding_account_id', $request), 'Funding account must be omitted for Plaid Ledger.');
bmfp_assert_same((string) $order->get_id(), $request['metadata']['bmfp_order_id'], 'Intent metadata binds the order.');
bmfp_assert_same('PAYMENT', $request['description'], 'Statement descriptor, not an order number.');
bmfp_assert_same('Anne Charleston', $request['user']['legal_name'], 'Legal name from the billing name.');
$intent_id = bmfp_meta($order, OrderMeta::TRANSFER_INTENT_ID);
bmfp_assert('' !== $intent_id, 'Intent ID persisted.');
bmfp_assert_same(PaymentState::INTENT_CREATED, bmfp_meta($order, OrderMeta::PAYMENT_STATE), 'State after checkout.');
bmfp_assert_same('pending', bmfp_reload($order)->get_status(), 'Order stays pending until the transfer exists.');
$snapshot = json_decode(bmfp_meta($order, OrderMeta::PAYMENT_SNAPSHOT), true);
bmfp_assert_same('11.11', $snapshot['amount'], 'Snapshot amount.');
bmfp_assert_same('sandbox', $snapshot['environment'], 'Snapshot environment.');
$lock = ( new PaymentLockStore() )->row($order->get_id());
bmfp_assert('created' === $lock['status'] && $intent_id === $lock['transfer_intent_id'], 'Durable reservation records the intent.');

// Idempotency: repeated process_payment and payment page refresh reuse the intent.
bmfp_checkout($order);
bmfp_checkout($order);
bmfp_assert_same(1, count($mock::calls('/transfer/intent/create')), 'Repeated process_payment must not create another intent.');
bmfp_assert_same($intent_id, bmfp_meta($order, OrderMeta::TRANSFER_INTENT_ID), 'Intent is reused.');

// Link token bound to the stored intent; opening Link again reuses the same intent.
$token = bmfp_token($order);
$second_token = bmfp_token($order);
$link_calls = $mock::calls('/link/token/create');
bmfp_assert_same(2, count($link_calls), 'Two Link tokens for two opens.');
foreach ($link_calls as $call) {
    bmfp_assert_same(array('intent_id' => $intent_id), $call['body']['transfer'], 'Link token must be bound to the stored intent.');
    bmfp_assert(! isset($call['body']['amount']), 'Link token request must not carry an amount.');
}
bmfp_assert_same(PaymentState::INTENT_PENDING, bmfp_meta($order, OrderMeta::PAYMENT_STATE), 'State after Link token.');
bmfp_assert('' !== bmfp_meta($order, OrderMeta::LINK_TOKEN_EXPIRES_AT), 'Authorization window recorded.');
bmfp_assert(PaymentAttemptService::authorization_window_open(bmfp_reload($order)), 'Authorization window is open.');

// Completion before the customer authorized is not success.
$completion = bmfp_c()->completion()->complete(bmfp_reload($order));
bmfp_assert_same(CompletionResult::INCOMPLETE, $completion->status, 'Completion without authorization.');
bmfp_assert(! bmfp_reload($order)->is_paid(), 'Browser signal alone never pays the order.');

// Customer completes Transfer UI; the server verifies with /transfer/intent/get.
$transfer_id = $mock::authorize($second_token);
$completion = bmfp_c()->completion()->complete(bmfp_reload($order));
bmfp_assert_same(CompletionResult::SUBMITTED, $completion->status, 'Completion after authorization.');
bmfp_assert_same($transfer_id, bmfp_meta($order, OrderMeta::TRANSFER_ID), 'Transfer ID persisted from Plaid.');
bmfp_assert_same('on-hold', bmfp_reload($order)->get_status(), 'New ACH transfer → on-hold.');
bmfp_assert(! bmfp_reload($order)->is_paid(), 'Pending ACH is not paid.');
bmfp_assert_same($transfer_id, bmfp_reload($order)->get_transaction_id(), 'WooCommerce transaction ID is the Plaid transfer ID.');
bmfp_c()->completion()->complete(bmfp_reload($order));
bmfp_assert_same(1, bmfp_note_count(bmfp_reload($order), 'Plaid transfer created'), 'Repeated completion is idempotent.');
bmfp_assert_same(0, count(array_filter($mock::calls('/link/token/create'), static fn (array $c): bool => ($c['body']['transfer']['intent_id'] ?? '') !== $intent_id)), 'No token for other intents.');

// Plaid lifecycle via verified webhook → event sync.
$mock::advance($transfer_id);
$response = bmfp_webhook();
bmfp_assert_same(200, $response['status'], 'Verified webhook accepted.');
bmfp_assert_same(1, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Webhook enqueues event sync.');
bmfp_webhook();
bmfp_assert_same(1, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Duplicate webhook coalesces into one job.');
bmfp_assert(! bmfp_reload($order)->is_paid(), 'The webhook itself never changes the order.');
bmfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK);
$paid = bmfp_reload($order);
bmfp_assert_same(PaymentState::FUNDS_AVAILABLE, $paid->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after events.');
bmfp_assert($paid->is_paid() && 'processing' === $paid->get_status(), 'Funds available → paid (processing).');
bmfp_assert_same('4', $paid->get_meta(OrderMeta::LAST_EVENT_ID, true), 'Last event ID recorded.');
$paid_at = $paid->get_date_paid()?->getTimestamp();
bmfp_assert_same('4', ( new \Buckmerce\Plaid\Persistence\EventCursor() )->get(bmfp_scope()), 'Cursor advanced to highest event.');
bmfp_assert(false === get_option('buckmerce_plaid_event_cursor_sandbox'), 'No per-environment cursor exists: the cursor belongs to the Plaid account.');

// Same events again: harmless.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_events SET status = 'received', attempts = 0 WHERE transfer_id = %s", $transfer_id));
bmfp_sync_events();
$again = bmfp_reload($order);
bmfp_assert_same($paid_at, $again->get_date_paid()?->getTimestamp(), 'Replayed events must not re-complete payment.');
bmfp_assert_same(1, bmfp_note_count($again, 'funds available'), 'Replayed events add no notes.');
bmfp_assert_same(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buckmerce_plaid_events WHERE status NOT IN ('processed','ignored')"), 'All events processed.');
bmfp_assert_same(4, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buckmerce_plaid_events"), 'Unique event identity: no duplicates stored.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $22.22: pending → failed, then a safe retry');
$failed = bmfp_order('22.22');
bmfp_checkout($failed);
$failed_token = bmfp_token($failed);
$failed_transfer = $mock::authorize($failed_token);
bmfp_c()->completion()->complete(bmfp_reload($failed));
$mock::advance($failed_transfer);
bmfp_sync_events();
$failed = bmfp_reload($failed);
bmfp_assert_same(PaymentState::FAILED, $failed->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after failure.');
bmfp_assert_same('failed', $failed->get_status(), 'Failed transfer → failed order.');
bmfp_assert(! $failed->is_paid() && null === $failed->get_date_paid(), 'A failed payment is never paid.');
$creates_before = count($mock::calls('/transfer/intent/create'));
bmfp_checkout($failed);
bmfp_assert_same($creates_before + 1, count($mock::calls('/transfer/intent/create')), 'Retry after failure creates exactly one new intent.');
$retired = bmfp_reload($failed)->get_meta(OrderMeta::RETIRED_ATTEMPTS, true);
bmfp_assert(is_array($retired) && 1 === count($retired) && $failed_transfer === $retired[0]['transfer_id'], 'Failed attempt archived.');
$mock::add_event($failed_transfer, 'posted');
bmfp_sync_events();
bmfp_assert_same(PaymentState::INTENT_CREATED, bmfp_meta($failed, OrderMeta::PAYMENT_STATE), 'Late event of a retired attempt cannot touch the new attempt.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Scenario $33.33: funds available, then ACH return R01');
delete_option('bmfp_test_mails');
$returned = bmfp_order('33.33');
$returned_hooks = 0;
add_action('buckmerce_plaid_payment_returned', static function () use (&$returned_hooks): void {
    ++$returned_hooks;
});
bmfp_checkout($returned);
$returned_transfer = $mock::authorize(bmfp_token($returned));
bmfp_c()->completion()->complete(bmfp_reload($returned));
$mock::advance($returned_transfer);
bmfp_sync_events();
$returned = bmfp_reload($returned);
bmfp_assert_same(PaymentState::RETURNED, $returned->get_meta(OrderMeta::PAYMENT_STATE, true), 'State after return.');
bmfp_assert_same('R01', $returned->get_meta(OrderMeta::RETURN_CODE, true), 'Return code stored.');
bmfp_assert_same('failed', $returned->get_status(), 'Returned ACH → order failed.');
bmfp_assert(! $returned->is_paid(), 'Returned payment is no longer paid.');
bmfp_assert(null !== $returned->get_date_paid(), 'Original payment history is preserved.');
$return_notes = array_values(array_filter(bmfp_notes($returned), static fn (string $note): bool => str_contains($note, 'ACH RETURN')));
bmfp_assert(1 === count($return_notes) && str_contains($return_notes[0], 'R01'), 'Private return note with reason.');
bmfp_assert(1 === bmfp_note_count($returned, 'will not be debited again by bank'), 'The merchant is told the order is not debited again.');
$alerts = get_option('buckmerce_plaid_payment_alerts');
bmfp_assert(is_array($alerts) && isset($alerts[$returned->get_id() . ':returned']), 'Admin alert recorded.');
$mails = get_option('bmfp_test_mails');
bmfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $m): bool => str_contains((string) $m['subject'], 'ACH return'))), 'Merchant emailed once.');
bmfp_assert_same(1, $returned_hooks, 'Return hook fired once.');
$mock::add_event($returned_transfer, 'funds_available');
bmfp_sync_events();
bmfp_assert_same(PaymentState::RETURNED, bmfp_meta($returned, OrderMeta::PAYMENT_STATE), 'Late success event never overrides a return.');
bmfp_assert(! bmfp_reload($returned)->is_paid(), 'Returned order stays unpaid.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Out-of-order events');
$ooo = bmfp_order('11.11');
bmfp_checkout($ooo);
$ooo_transfer = $mock::authorize(bmfp_token($ooo));
bmfp_c()->completion()->complete(bmfp_reload($ooo));
$mock::add_event($ooo_transfer, 'settled');
$mock::add_event($ooo_transfer, 'posted');
bmfp_sync_events();
bmfp_assert_same(PaymentState::SETTLED, bmfp_meta($ooo, OrderMeta::PAYMENT_STATE), 'Posted after settled must not regress.');
bmfp_assert_same('on-hold', bmfp_reload($ooo)->get_status(), 'Settled with funds_available policy stays on-hold.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Declined (NSF) and failed authorizations');
$nsf = bmfp_order('41.00');
bmfp_checkout($nsf);
$nsf_intent = bmfp_meta($nsf, OrderMeta::TRANSFER_INTENT_ID);
$mock::authorize(bmfp_token($nsf), 'nsf');
$result = bmfp_c()->completion()->complete(bmfp_reload($nsf));
bmfp_assert(CompletionResult::INCOMPLETE === $result->status && 'NSF' === $result->reason_code, 'NSF decline is incomplete and retryable.');
bmfp_checkout($nsf);
bmfp_assert_same($nsf_intent, bmfp_meta($nsf, OrderMeta::TRANSFER_INTENT_ID), 'NSF retry reuses the pending intent.');
$declined = bmfp_order('42.00');
bmfp_checkout($declined);
$declined_intent = bmfp_meta($declined, OrderMeta::TRANSFER_INTENT_ID);
$mock::authorize(bmfp_token($declined), 'failed');
bmfp_assert_same(CompletionResult::FAILED, bmfp_c()->completion()->complete(bmfp_reload($declined))->status, 'Failed intent reported.');
bmfp_assert_same(PaymentState::INTENT_FAILED, bmfp_meta($declined, OrderMeta::PAYMENT_STATE), 'Intent failed state.');
bmfp_token($declined);
bmfp_assert($declined_intent !== bmfp_meta($declined, OrderMeta::TRANSFER_INTENT_ID), 'A failed intent is replaced before a new Link token.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Ambiguous create outcomes (timeout, 5xx, response lost after creation)');
$orphans = array();
foreach (array('timeout_after_create', 'server_error', 'timeout') as $mode) {
    $ambiguous = bmfp_order('12.00');
    $known = array_keys($mock::state()['intents']);
    $mock::fail_next('/transfer/intent/create', $mode);
    $result = bmfp_checkout($ambiguous);
    bmfp_assert_same('failure', $result['result'], 'Ambiguous create fails checkout (' . $mode . ').');
    bmfp_assert_same(PaymentState::INTENT_UNCERTAIN, bmfp_meta($ambiguous, OrderMeta::PAYMENT_STATE), 'Ambiguous create → uncertain (' . $mode . ').');
    bmfp_assert_same('', bmfp_meta($ambiguous, OrderMeta::TRANSFER_INTENT_ID), 'No intent ID was stored (' . $mode . ').');
    bmfp_assert_same('uncertain', ( new PaymentLockStore() )->row($ambiguous->get_id())['status'], 'Reservation uncertain (' . $mode . ').');
    // Intents Plaid created but Buckmerce never learned about (response lost).
    $orphans = array_merge($orphans, array_diff(array_keys($mock::state()['intents']), $known));
    $result = bmfp_checkout($ambiguous);
    bmfp_assert_same('success', $result['result'], 'Retry succeeds (' . $mode . ').');
    $new_intent = bmfp_meta($ambiguous, OrderMeta::TRANSFER_INTENT_ID);
    bmfp_assert(! in_array($new_intent, $orphans, true), 'Retry uses a new intent (' . $mode . ').');
    bmfp_token($ambiguous);
}
bmfp_assert(count($orphans) >= 1, 'The response-lost scenario created a remote orphan intent.');
$tokenized = array_map(static fn (array $call): string => (string) $call['body']['transfer']['intent_id'], $mock::calls('/link/token/create'));
bmfp_assert(array() === array_intersect($orphans, $tokenized), 'An intent whose create response was lost can never receive a Link token, so it can never move money.');

WP_CLI::log('Definitive rejection and local persistence failure after remote creation');
$rejected = bmfp_order('13.00');
$mock::fail_next('/transfer/intent/create', 'reject');
bmfp_assert_same('failure', bmfp_checkout($rejected)['result'], 'Definitive rejection fails checkout.');
bmfp_assert_same(PaymentState::INTENT_FAILED, bmfp_meta($rejected, OrderMeta::PAYMENT_STATE), 'Rejected create → intent_failed.');
bmfp_assert_same('success', bmfp_checkout($rejected)['result'], 'Retry after rejection succeeds.');

$lost = bmfp_order('14.00');
$fail_save = static function (WC_Order $order): void {
    if ('' !== (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true) && PaymentState::INTENT_CREATING === (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true)) {
        throw new RuntimeException('Simulated database failure while saving the intent ID.');
    }
};
add_action('woocommerce_before_order_object_save', $fail_save);
$creates_before = count($mock::calls('/transfer/intent/create'));
bmfp_assert_same('failure', bmfp_checkout($lost)['result'], 'Local persistence failure fails checkout.');
remove_action('woocommerce_before_order_object_save', $fail_save);
bmfp_assert_same('', bmfp_meta($lost, OrderMeta::TRANSFER_INTENT_ID), 'Intent ID not on the order yet.');
$lock = ( new PaymentLockStore() )->row($lost->get_id());
bmfp_assert('created' === $lock['status'] && '' !== $lock['transfer_intent_id'], 'Intent ID durable in the reservation.');
bmfp_assert_same('success', bmfp_checkout($lost)['result'], 'Retry recovers.');
bmfp_assert_same($creates_before + 1, count($mock::calls('/transfer/intent/create')), 'Recovery adopts the saved intent instead of creating another.');
bmfp_assert_same($lock['transfer_intent_id'], bmfp_meta($lost, OrderMeta::TRANSFER_INTENT_ID), 'Adopted intent ID.');

WP_CLI::log('Lock contention, DB failure and expired leases fail closed');
$busy = bmfp_order('15.00');
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lock_name = 'bmfp_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':buckmerce-plaid:payment:' . $busy->get_id()), 0, 48);
bmfp_assert_same('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)), 'Second connection holds the payment lock.');
$creates_before = count($mock::calls('/transfer/intent/create'));
bmfp_assert_same('failure', bmfp_checkout($busy)['result'], 'Concurrent worker → busy.');
bmfp_assert_same($creates_before, count($mock::calls('/transfer/intent/create')), 'No Plaid call while another worker holds the lock.');
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
$other->close();

$dbfail = bmfp_order('16.00');
$locks_table = Installer::locks_table();
$wpdb->query("RENAME TABLE {$locks_table} TO {$locks_table}_offline");
$suppress = $wpdb->suppress_errors(true);
$result = bmfp_checkout($dbfail);
$wpdb->suppress_errors($suppress);
$wpdb->query("RENAME TABLE {$locks_table}_offline TO {$locks_table}");
bmfp_assert_same('failure', $result['result'], 'Reservation DB failure fails checkout.');
bmfp_assert_same($creates_before, count($mock::calls('/transfer/intent/create')), 'No remote payment without a durable reservation.');

$crashed = bmfp_order('17.00');
$wpdb->insert($locks_table, array('order_id' => $crashed->get_id(), 'environment' => 'sandbox', 'status' => 'creating', 'owner_token' => str_repeat('a', 64), 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 600), 'attempts' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')));
$crashed->update_meta_data(OrderMeta::PAYMENT_STATE, PaymentState::INTENT_CREATING);
$crashed->save();
bmfp_assert_same('failure', bmfp_checkout($crashed)['result'], 'A live creating lease blocks a second worker.');
$wpdb->update($locks_table, array('lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 5)), array('order_id' => $crashed->get_id()));
bmfp_assert_same('success', bmfp_checkout($crashed)['result'], 'An expired lease from a crashed worker is recovered.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($crashed), 'unknown outcome'), 'Abandoned attempt recorded as uncertain.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Access control: order key, ownership and REST authorization');
$customer = wp_insert_user(array('user_login' => 'bmfp_customer_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'c' . wp_rand() . '@example.com'));
$intruder = wp_insert_user(array('user_login' => 'bmfp_intruder_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'i' . wp_rand() . '@example.com'));
$owned = bmfp_order('18.00', (int) $customer);
$access = new PaymentAccess();
wp_set_current_user((int) $customer);
bmfp_assert($access->can_access($owned, $owned->get_order_key()), 'Owner with key may pay.');
bmfp_assert(! $access->can_access($owned, 'wc_order_wrongkey'), 'Wrong key denied.');
bmfp_assert(! $access->can_access($owned, ''), 'Missing key denied.');
wp_set_current_user((int) $intruder);
bmfp_assert(! $access->can_access($owned, $owned->get_order_key()), 'Another customer is denied even with the key.');
wp_set_current_user(0);
bmfp_assert(! $access->can_access($owned, $owned->get_order_key()), 'Logged-out visitor is denied for a registered order.');
$guest = bmfp_order('19.00');
bmfp_assert(! $access->can_access($guest, $guest->get_order_key()), 'Guest order without a session grant is denied.');
$access->grant($guest);
bmfp_assert($access->can_access($guest, $guest->get_order_key()), 'Guest with a session grant may pay.');
$real_key = $guest->get_order_key();
// Always a different key (replacing the last character with a fixed one would equal the real key when it already ends with it).
$wrong_key = substr($real_key, 0, -1) . ('x' === substr($real_key, -1) ? 'y' : 'x');
bmfp_assert(! $access->can_access($guest, $wrong_key), 'Guest with the wrong key is denied.');

wp_set_current_user((int) $customer);
bmfp_checkout($owned);
$nonce = wp_create_nonce(PaymentAccess::nonce_action($owned->get_id()));
$ok = bmfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => $nonce));
bmfp_assert(200 === $ok['status'] && 'ready' === $ok['data']['status'] && str_starts_with((string) $ok['data']['link_token'], 'link-sandbox-'), 'Authorized REST Link token.');
bmfp_assert(! isset($ok['data']['amount']) && ! str_contains((string) wp_json_encode($ok['data']), 'test-sandbox-secret'), 'Link token response leaks nothing sensitive.');
$bad_key = bmfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => 'wc_order_forged', 'payment_nonce' => $nonce));
bmfp_assert_same(403, $bad_key['status'], 'Forged key rejected.');
$bad_nonce = bmfp_rest('/link-token', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => 'deadbeef00'));
bmfp_assert_same(403, $bad_nonce['status'], 'Invalid nonce rejected.');
$missing = bmfp_rest('/link-token', array('order_id' => $owned->get_id()));
bmfp_assert_same(400, $missing['status'], 'Schema requires order key and nonce.');
// A different browser session: no grant for the guest order, and another user's order.
WC()->session->set('bmfp_payment_grants', array());
$other_order = bmfp_rest('/complete', array('order_id' => $guest->get_id(), 'order_key' => $guest->get_order_key(), 'payment_nonce' => wp_create_nonce(PaymentAccess::nonce_action($guest->get_id()))));
bmfp_assert_same(403, $other_order['status'], 'Another session cannot complete a guest order even with its key (IDOR).');
$intruder_order = bmfp_order('18.50', (int) $intruder);
$foreign = bmfp_rest('/link-token', array('order_id' => $intruder_order->get_id(), 'order_key' => $intruder_order->get_order_key(), 'payment_nonce' => wp_create_nonce(PaymentAccess::nonce_action($intruder_order->get_id()))));
bmfp_assert_same(403, $foreign['status'], 'A customer cannot request a Link token for another customer order (IDOR).');
$forged_success = bmfp_rest('/complete', array('order_id' => $owned->get_id(), 'order_key' => $owned->get_order_key(), 'payment_nonce' => $nonce, 'status' => 'SUCCEEDED', 'transfer_id' => 'forged', 'amount' => '0.01'));
bmfp_assert(200 === $forged_success['status'] && CompletionResult::INCOMPLETE === $forged_success['data']['status'], 'Browser-submitted success/transfer/amount are ignored.');
bmfp_assert('' === bmfp_meta($owned, OrderMeta::TRANSFER_ID) && ! bmfp_reload($owned)->is_paid(), 'Forged completion did not mutate the order.');
wp_set_current_user(0);

// ---------------------------------------------------------------------------------
WP_CLI::log('Cancelled order, amount mismatch, lost webhook reconciliation, adoption');
$cancel = bmfp_order('20.00');
bmfp_checkout($cancel);
$cancel_token = bmfp_token($cancel);
bmfp_assert(false === ( new PaymentPage() )->keep_order_during_authorization(true, bmfp_reload($cancel)), 'Unpaid-order auto-cancel is suppressed while Link can be used.');
bmfp_reload($cancel)->update_status('cancelled');
$cancel_transfer = $mock::authorize($cancel_token);
$mock::advance($cancel_transfer);
bmfp_c()->completion()->complete(bmfp_reload($cancel));
bmfp_sync_events();
$cancel = bmfp_reload($cancel);
bmfp_assert_same(PaymentState::MANUAL_REVIEW, $cancel->get_meta(OrderMeta::PAYMENT_STATE, true), 'Money for a cancelled order → manual review.');
bmfp_assert_same('payment_for_cancelled_order', $cancel->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true), 'Manual review reason.');
bmfp_assert(! $cancel->is_paid(), 'Cancelled order is not auto-fulfilled.');

$mismatch = bmfp_order('21.00');
bmfp_checkout($mismatch);
$mismatch_transfer = $mock::authorize(bmfp_token($mismatch), 'success', '0.01');
bmfp_c()->completion()->complete(bmfp_reload($mismatch));
bmfp_assert_same(PaymentState::MANUAL_REVIEW, bmfp_meta($mismatch, OrderMeta::PAYMENT_STATE), 'Wrong transfer amount → manual review.');
$mock::advance($mismatch_transfer);
bmfp_sync_events();
bmfp_assert(! bmfp_reload($mismatch)->is_paid(), 'A wrong amount is never accepted as payment.');

$lost_hook = bmfp_order('11.11');
bmfp_checkout($lost_hook);
$lost_transfer = $mock::authorize(bmfp_token($lost_hook));
// The customer closed the page: no completion call and no webhook.
$mock::advance($lost_transfer);
$stale = bmfp_reload($lost_hook);
$stale->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c', time() - 3600));
$stale->save();
$report = bmfp_c()->reconciliation()->run();
bmfp_assert_same('ok', $report['status'], 'Reconciliation run.');
$lost_hook = bmfp_reload($lost_hook);
bmfp_assert_same($lost_transfer, $lost_hook->get_meta(OrderMeta::TRANSFER_ID, true), 'Reconciliation/event adoption bound the transfer.');
bmfp_assert(PaymentState::FUNDS_AVAILABLE === $lost_hook->get_meta(OrderMeta::PAYMENT_STATE, true) && $lost_hook->is_paid(), 'Lost webhook recovered by reconciliation.');
bmfp_assert('' !== (string) get_option('buckmerce_plaid_last_reconciliation'), 'Reconciliation timestamp recorded.');

$manual = bmfp_order('11.11');
bmfp_checkout($manual);
$manual_transfer = $mock::authorize(bmfp_token($manual));
bmfp_c()->synchronizer()->sync(bmfp_reload($manual));
bmfp_assert_same($manual_transfer, bmfp_meta($manual, OrderMeta::TRANSFER_ID), 'Manual Sync with Plaid binds the transfer.');
bmfp_assert_same('on-hold', bmfp_reload($manual)->get_status(), 'Manual sync projects on-hold.');

WP_CLI::log('Foreign transfers on a shared Plaid account are ignored, not retried');
$shared = bmfp_order('11.11');
bmfp_checkout($shared);
$shared_snapshot = json_decode(bmfp_meta($shared, OrderMeta::PAYMENT_SNAPSHOT), true);
$foreign = array(
    'foreign_transfer' => $mock::foreign_transfer(null),
    'foreign_site' => $mock::foreign_transfer(array('bmfp_order_id' => (string) $shared->get_id(), 'bmfp_attempt_id' => $shared_snapshot['attempt_id'], 'bmfp_environment' => 'sandbox', 'bmfp_site' => 'another-store-000')),
    'order_missing' => $mock::foreign_transfer(array('bmfp_order_id' => '999999', 'bmfp_attempt_id' => str_repeat('a', 32), 'bmfp_environment' => 'sandbox')),
    'unknown_attempt' => $mock::foreign_transfer(array('bmfp_order_id' => (string) $shared->get_id(), 'bmfp_attempt_id' => str_repeat('b', 32), 'bmfp_environment' => 'sandbox')),
);
bmfp_sync_events();
foreach ($foreign as $code => $foreign_transfer) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_code FROM {$wpdb->prefix}buckmerce_plaid_events WHERE transfer_id = %s", $foreign_transfer), ARRAY_A);
    bmfp_assert('ignored' === $row['status'] && $code === $row['error_code'], 'Foreign transfer classified as ' . $code . ' (got ' . wp_json_encode($row) . ').');
}
bmfp_assert_same(PaymentState::INTENT_CREATED, bmfp_meta($shared, OrderMeta::PAYMENT_STATE), 'Foreign transfers never touch a local order with the same number.');
// History of the shared Plaid account from before this store's first intent is ignored without API calls.
bmfp_assert(null !== \Buckmerce\Plaid\Persistence\PaymentEpoch::get(bmfp_scope()), 'The first Transfer Intent recorded the payment epoch.');
$history_gets = count($mock::calls('/transfer/get'));
$history_transfer = $mock::foreign_transfer(array('bmfp_order_id' => (string) $shared->get_id(), 'bmfp_attempt_id' => str_repeat('c', 32), 'bmfp_environment' => 'sandbox'), '5.00', \Buckmerce\Plaid\Persistence\PaymentEpoch::get(bmfp_scope()) - 2 * HOUR_IN_SECONDS);
bmfp_sync_events();
$history_row = $wpdb->get_row($wpdb->prepare("SELECT status, error_code FROM {$wpdb->prefix}buckmerce_plaid_events WHERE transfer_id = %s", $history_transfer), ARRAY_A);
bmfp_assert('ignored' === $history_row['status'] && 'before_first_payment' === $history_row['error_code'], 'Pre-epoch history is ignored (got ' . wp_json_encode($history_row) . ').');
bmfp_assert_same($history_gets, count($mock::calls('/transfer/get')), 'Pre-epoch history costs no /transfer/get call.');

// Rate limiting (HTTP 429) on /transfer/get is retried later, never a final classification.
$limited_transfer = $mock::foreign_transfer(null);
$mock::fail_next('/transfer/get', 'rate_limit');
bmfp_sync_events();
$limited_row = $wpdb->get_row($wpdb->prepare("SELECT status, error_code FROM {$wpdb->prefix}buckmerce_plaid_events WHERE transfer_id = %s", $limited_transfer), ARRAY_A);
bmfp_assert('unmatched' === $limited_row['status'], 'A rate-limited transfer lookup keeps the event retryable (got ' . wp_json_encode($limited_row) . ').');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_events SET lease_expires_at = %s WHERE transfer_id = %s", gmdate('Y-m-d H:i:s', time() - 60), $limited_transfer));
bmfp_sync_events();
$limited_row = $wpdb->get_row($wpdb->prepare("SELECT status, error_code FROM {$wpdb->prefix}buckmerce_plaid_events WHERE transfer_id = %s", $limited_transfer), ARRAY_A);
bmfp_assert('ignored' === $limited_row['status'] && 'foreign_transfer' === $limited_row['error_code'], 'After the rate limit clears the event is classified (got ' . wp_json_encode($limited_row) . ').');
$site_marker = (string) ($mock::calls('/transfer/intent/create')[count($mock::calls('/transfer/intent/create')) - 1]['body']['metadata']['bmfp_site'] ?? '');
bmfp_assert(1 === preg_match('/^[a-f0-9]{16}$/', $site_marker), 'Intents carry the store marker.');

WP_CLI::success('Buckmerce payment flow suite passed (HPOS=' . (getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ?: '?') . ').');
