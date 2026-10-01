<?php

/**
 * Account-scoped Plaid event streams (ADR-0018) against real WordPress/WooCommerce and the
 * per-client Plaid double: a merchant legitimately switches Plaid accounts in one environment
 * after account A processed events 1..1000; account B's stream starts at 1 again. Cursors,
 * epochs, stored events, refund identities, diagnostics and webhooks must stay per account.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Admin\DiagnosticsPage;
use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Refund\RefundState;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Settings\Settings;

global $wpdb;
$mock = Buckmerce_Test_Plaid_Mock::class;
$https = static fn ($home) => str_replace('http://', 'https://', (string) $home);
bmfp_configure();
bmfp_reset_world();
// Each run starts with accounts that never paid here (the suite runs once per HPOS mode).
foreach (array('prodclienta', 'prodclientb', 'sandboxclienta', 'sandboxclientb') as $client) {
    foreach (array('sandbox', 'production') as $environment) {
        delete_option(PaymentEpoch::option_name(new AccountScope($environment, Buckmerce\Plaid\Settings\AccountIdentity::fingerprint($client))));
    }
}

function bmfp_ac(): Container
{
    return new Container();
}

/** @return array{WC_Order, string} Checkout + Transfer UI + completion with the configured account. */
function bmfp_ac_pay(string $total): array
{
    $order = bmfp_order($total);
    bmfp_assert('success' === bmfp_gateway()->process_payment($order->get_id())['result'], 'Checkout ' . $total);
    $token = bmfp_ac()->attempts()->issue_link_token(bmfp_reload($order));
    bmfp_assert(null !== $token, 'Link token ' . $total);
    $transfer = Buckmerce_Test_Plaid_Mock::authorize($token->token);
    bmfp_ac()->completion()->complete(bmfp_reload($order));
    return array(bmfp_reload($order), $transfer);
}

/** Runs event sync until the stream and the stored backlog are drained. */
function bmfp_ac_drain(): void
{
    for ($run = 0; $run < 30; ++$run) {
        $result = bmfp_ac()->event_sync()->run();
        bmfp_assert('ok' === $result['status'], 'Event sync run ' . $run . ': ' . $result['status']);
        if (! $result['more']) {
            return;
        }
    }
    bmfp_assert(false, 'The event stream did not drain.');
}

/** @return array<string, mixed>|null */
function bmfp_ac_event(AccountScope $scope, string $event_id): ?array
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}buckmerce_plaid_events WHERE environment = %s AND account_fp = %s AND event_id = %s", $scope->environment, $scope->account_fp, $event_id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** Plaid's return windows of the payment have closed (as months later). */
function bmfp_ac_close(WC_Order $order): void
{
    $order = bmfp_reload($order);
    $order->update_meta_data(OrderMeta::SETTLED_AT, gmdate('c', time() - 200 * DAY_IN_SECONDS));
    $order->update_meta_data(OrderMeta::STANDARD_RETURN_WINDOW, gmdate('Y-m-d', time() - 190 * DAY_IN_SECONDS));
    $order->update_meta_data(OrderMeta::UNAUTHORIZED_RETURN_WINDOW, gmdate('Y-m-d', time() - 100 * DAY_IN_SECONDS));
    $order->save();
    bmfp_ac()->monitor()->refresh(bmfp_reload($order));
}

/** @return list<string> Plaid client IDs used for /transfer/event/sync since call index $from. */
function bmfp_ac_sync_clients(int $from): array
{
    $calls = array_slice(Buckmerce_Test_Plaid_Mock::state()['calls'], $from);
    return array_values(array_unique(array_column(array_filter($calls, static fn (array $call): bool => '/transfer/event/sync' === $call['path']), 'client_id')));
}

$cursor = new EventCursor();

// ---------------------------------------------------------------------------------
WP_CLI::log('Production account A processes its stream through event 1000, then everything closes');
add_filter('option_home', $https);
bmfp_configure(array('environment' => 'production', 'client_id' => 'prodclienta', 'secret' => 'prod-secret-a', 'link_customization_name' => 'one_account'));
$scope_a = bmfp_scope();
bmfp_assert($scope_a->is_valid() && 'production' === $scope_a->environment, 'Account A scope.');
[$order_a, $transfer_a] = bmfp_ac_pay('40.00');
foreach (array('posted', 'settled', 'funds_available') as $type) {
    $mock::add_event($transfer_a, $type);
}
bmfp_ac_drain();
bmfp_assert(bmfp_reload($order_a)->is_paid(), 'Account A payment paid.');
$refund_a = bmfp_wc_refund(bmfp_reload($order_a), '5.00');
bmfp_assert($refund_a instanceof WC_Order_Refund, 'Account A refund.');
$refund_a_row = bmfp_refund_rows($order_a)[0];
bmfp_assert_same($scope_a->account_fp, (string) $refund_a_row['account_fp'], 'The refund records the account that created it.');
$mock::add_refund_event((string) $refund_a_row['refund_id'], 'refund.posted');
$mock::add_refund_event((string) $refund_a_row['refund_id'], 'refund.settled');
$epoch_a = PaymentEpoch::get($scope_a);
bmfp_assert(null !== $epoch_a, 'Account A epoch.');
// Other integrations' history on account A fills the stream up to event 1000.
$mock::add_history('prodclienta', 1000 - $mock::last_event_id('prodclienta'), $epoch_a - 3 * DAY_IN_SECONDS);
bmfp_assert_same(1000, $mock::last_event_id('prodclienta'), 'Account A stream: events 1..1000.');
bmfp_ac_drain();
bmfp_assert_same('1000', $cursor->get($scope_a), 'Account A processed through event 1000.');
bmfp_assert_same(RefundState::SETTLED, (string) bmfp_refund_rows($order_a)[0]['status'], 'Account A refund settled.');
bmfp_assert_same(1000, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}buckmerce_plaid_events WHERE environment = %s AND account_fp = %s", 'production', $scope_a->account_fp)), 'Every account A event is stored in account A scope.');
bmfp_ac_close($order_a);
( new RefundStore() )->schedule((int) $refund_a_row['id'], null, null);
bmfp_assert(! Scheduler::has_work(Scheduler::pending_work($scope_a)), 'All monitored payments and refunds of account A closed.');

// ---------------------------------------------------------------------------------
WP_CLI::log('The merchant switches to Production account B: its stream starts again at 1');
update_option(BMFP_TEST_SETTINGS_OPTION, array('client_id' => 'prodclientb', 'secret' => 'prod-secret-b') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('prodclientb', Settings::load()->client_id(), 'The switch is allowed once nothing of account A is monitored.');
$scope_b = bmfp_scope();
bmfp_assert(! $scope_b->equals($scope_a) && 'production' === $scope_b->environment, 'Same environment, different account scope.');
bmfp_assert_same('0', $cursor->get($scope_b), 'Account B cursor starts at 0.');
bmfp_assert_same('1000', $cursor->get($scope_a), 'Account A cursor preserved for audit.');
bmfp_assert(null === PaymentEpoch::get($scope_b), 'Account B has no epoch before its first payment.');
$calls_before = count($mock::state()['calls']);
[$order_b, $transfer_b] = bmfp_ac_pay('41.00');
bmfp_assert_same(1, $mock::last_event_id('prodclientb'), 'Account B event stream begins at event 1.');
bmfp_assert(null !== PaymentEpoch::get($scope_b) && PaymentEpoch::get($scope_b) >= $epoch_a, 'Account B got its own epoch.');
bmfp_assert_same($epoch_a, PaymentEpoch::get($scope_a), 'Account A epoch is unchanged.');
$a_event_1 = bmfp_ac_event($scope_a, '1');
bmfp_assert(null !== $a_event_1 && $transfer_a === $a_event_1['transfer_id'], 'Account A event 1 exists.');
bmfp_ac_drain();
$b_event_1 = bmfp_ac_event($scope_b, '1');
bmfp_assert(null !== $b_event_1, 'Account B event 1 was stored, not discarded as a duplicate of account A event 1.');
bmfp_assert($transfer_b === $b_event_1['transfer_id'] && 'processed' === $b_event_1['status'] && (int) $b_event_1['order_id'] === $order_b->get_id(), 'Account B event 1 was processed for account B\'s order.');
bmfp_assert_same($a_event_1, bmfp_ac_event($scope_a, '1'), 'Account A event 1 is untouched.');
foreach (array('posted', 'settled', 'funds_available') as $type) {
    $mock::add_event($transfer_b, $type);
}
bmfp_ac_drain();
bmfp_assert(bmfp_reload($order_b)->is_paid(), 'Account B payment completed from account B\'s stream.');
bmfp_assert_same((string) $mock::last_event_id('prodclientb'), $cursor->get($scope_b), 'Only account B\'s cursor advanced.');
bmfp_assert_same('1000', $cursor->get($scope_a), 'Account A cursor unchanged.');
bmfp_assert_same(array('prodclientb'), bmfp_ac_sync_clients($calls_before), 'After the switch only account B credentials read events.');
bmfp_assert(PaymentState::FUNDS_AVAILABLE === bmfp_reload($order_a)->get_meta(OrderMeta::PAYMENT_STATE, true) && bmfp_reload($order_a)->is_paid(), 'Account A\'s order is unchanged.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Refund identities never cross accounts');
$refund_b = bmfp_wc_refund(bmfp_reload($order_b), '6.00');
bmfp_assert($refund_b instanceof WC_Order_Refund, 'Account B refund.');
$refund_b_row = bmfp_refund_rows($order_b)[0];
bmfp_assert_same($scope_b->account_fp, (string) $refund_b_row['account_fp'], 'Account B refund recorded for account B.');
$store = new RefundStore();
$a_refund_id = (string) $refund_a_row['refund_id'];
bmfp_assert(null === $store->find_by_refund_id($scope_b, $a_refund_id), 'Account A\'s refund ID is unknown in account B scope.');
// An identical refund ID reported by account B coexists with account A's refund (UNIQUE per account).
$collision = $store->insert_external(array('order_id' => $order_b->get_id(), 'environment' => 'production', 'account_fp' => $scope_b->account_fp, 'attempt_id' => str_repeat('b', 32), 'transfer_id' => $transfer_b, 'refund_id' => $a_refund_id, 'amount' => '1.00', 'currency' => 'USD', 'status' => 'pending', 'failure_code' => ''));
bmfp_assert(null !== $collision && $collision->order_id === $order_b->get_id(), 'Account B may have a refund with the same ID as account A.');
bmfp_assert_same($order_a->get_id(), $store->find_by_refund_id($scope_a, $a_refund_id)?->order_id, 'Lookup in account A scope returns account A\'s refund.');
bmfp_assert_same($order_b->get_id(), $store->find_by_refund_id($scope_b, $a_refund_id)?->order_id, 'Lookup in account B scope returns account B\'s refund.');
bmfp_assert(null === $store->insert_external(array('order_id' => $order_b->get_id(), 'environment' => 'production', 'account_fp' => $scope_b->account_fp, 'attempt_id' => str_repeat('b', 32), 'transfer_id' => $transfer_b, 'refund_id' => $a_refund_id, 'amount' => '1.00', 'currency' => 'USD', 'status' => 'pending', 'failure_code' => '')), 'The same account never records a refund twice.');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}buckmerce_plaid_refunds WHERE id = %d", $collision->id));
// A refund event on account B's stream carrying account A's refund ID never touches account A's refund.
$a_before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}buckmerce_plaid_refunds WHERE id = %d", $refund_a_row['id']), ARRAY_A);
$mock::add_raw_event('prodclientb', array('event_type' => 'refund.returned', 'transfer_id' => $transfer_b, 'refund_id' => $a_refund_id, 'transfer_amount' => '5.00', 'event_amount' => '5.00', 'failure_reason' => array('failure_code' => 'R01', 'ach_return_code' => 'R01', 'description' => 'x')));
bmfp_ac()->event_sync()->run();
bmfp_assert_same($a_before, $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}buckmerce_plaid_refunds WHERE id = %d", $refund_a_row['id']), ARRAY_A), 'Account A\'s refund is untouched by account B\'s event.');
bmfp_assert_same(2, count(bmfp_refund_rows($order_a)) + count(bmfp_refund_rows($order_b)), 'No refund row was invented from the foreign refund ID.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_events SET status = 'ignored' WHERE environment = 'production' AND account_fp = %s AND status IN ('retry', 'unmatched')", $scope_b->account_fp));

// ---------------------------------------------------------------------------------
WP_CLI::log('Attack: identical event IDs on both accounts; a verified account B webhook only touches account B');
$shared_id = $mock::last_event_id('prodclientb') + 1;
bmfp_assert($shared_id <= 1000, 'Precondition: account A has an event with the same ID.');
$a_shared = bmfp_ac_event($scope_a, (string) $shared_id);
bmfp_assert(null !== $a_shared, 'Account A event ' . $shared_id . ' exists.');
$mock::add_raw_event('prodclientb', array('event_id' => $shared_id, 'event_type' => 'funds_available', 'transfer_id' => $transfer_b, 'transfer_amount' => '41.00', 'event_amount' => '41.00'));
$calls_before = count($mock::state()['calls']);
$body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'production'));
$webhook = bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body);
bmfp_assert_same(200, $webhook['status'], 'Verified webhook accepted.');
bmfp_assert(1 <= bmfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK), 'The webhook queued an event sync.');
bmfp_assert_same(array('prodclientb'), bmfp_ac_sync_clients($calls_before), 'Only account B credentials were used.');
bmfp_assert_same((string) $shared_id, $cursor->get($scope_b), 'Only account B\'s cursor advanced.');
bmfp_assert_same('1000', $cursor->get($scope_a), 'Account A cursor untouched.');
$b_shared = bmfp_ac_event($scope_b, (string) $shared_id);
bmfp_assert(null !== $b_shared && 'processed' === $b_shared['status'] && (int) $b_shared['order_id'] === $order_b->get_id(), 'Account B event processed.');
bmfp_assert_same($a_shared, bmfp_ac_event($scope_a, (string) $shared_id), 'Account A event with the same ID untouched.');
bmfp_assert_same(2, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}buckmerce_plaid_events WHERE environment = 'production' AND event_id = %d", $shared_id)), 'Both events coexist.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Diagnostics describe the configured account, prior accounts stay auditable');
$report = DiagnosticsPage::report();
bmfp_assert_same('production / ' . $scope_b->account_fp, $report['Event stream (environment/account)'], 'Diagnostics show account B\'s stream.');
bmfp_assert_same((string) $shared_id, $report['Event stream cursor (last stored event ID)'], 'Diagnostics show account B\'s cursor.');
bmfp_assert((int) $report['Events of previous accounts or schema 2 (audit only)'] >= 1000, 'Account A\'s events are retained for audit.');
bmfp_assert_same('0', $report['Event backlog (waiting to be processed)'], 'No account B backlog.');
bmfp_assert(! str_contains(implode("\n", $report), 'prod-secret'), 'No secret in diagnostics.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Secret rotation of the same Client ID keeps the stream and its cursor');
$cursor_before = $cursor->get($scope_b);
update_option(BMFP_TEST_SETTINGS_OPTION, array('secret' => 'prod-secret-b-rotated') + get_option(BMFP_TEST_SETTINGS_OPTION));
bmfp_assert_same('prod-secret-b-rotated', Settings::load()->secret(), 'Rotation allowed.');
bmfp_assert(bmfp_scope()->equals($scope_b), 'Rotation keeps the account scope.');
bmfp_assert_same($cursor_before, $cursor->get(bmfp_scope()), 'Rotation never resets the cursor.');
$mock::add_event($transfer_b, 'funds_available');
$result = bmfp_ac()->event_sync()->run();
bmfp_assert_same(1, $result['fetched'], 'Only the new event is fetched after a rotation.');
bmfp_assert_same(0, EventSyncService::failures($scope_b), 'Healthy stream.');
// Leave Production: close account B's monitoring (as if its windows had passed).
bmfp_ac_close($order_b);
$wpdb->query("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET status = 'settled', reconcile_after = NULL, monitor_until = NULL WHERE environment = 'production'");
remove_filter('option_home', $https);

// ---------------------------------------------------------------------------------
WP_CLI::log('Sandbox A → Sandbox B: independent streams, account A payments never read with B');
bmfp_configure(array('client_id' => 'sandboxclienta'));
bmfp_assert_same('sandboxclienta', Settings::load()->client_id(), 'Switched to Sandbox account A.');
$sandbox_a = bmfp_scope();
[$open_a, $open_a_transfer] = bmfp_ac_pay('11.11');
bmfp_ac_drain();
bmfp_assert(null !== bmfp_ac_event($sandbox_a, '1'), 'Sandbox A event 1 stored.');
bmfp_configure(array('client_id' => 'sandboxclientb'));
bmfp_assert_same('sandboxclientb', Settings::load()->client_id(), 'Sandbox moves no real money: the switch is allowed with a warning.');
$sandbox_b = bmfp_scope();
bmfp_assert_same('0', $cursor->get($sandbox_b), 'Sandbox B starts at 0.');
[$open_b, $open_b_transfer] = bmfp_ac_pay('11.11');
bmfp_ac_drain();
bmfp_assert(null !== bmfp_ac_event($sandbox_b, '1') && null !== bmfp_ac_event($sandbox_a, '1'), 'Sandbox A and B event 1 coexist.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_payment_locks SET reconcile_after = %s WHERE order_id IN (%d, %d)", gmdate('Y-m-d H:i:s', time() - 60), $open_a->get_id(), $open_b->get_id()));
$calls_before = count($mock::state()['calls']);
bmfp_ac()->reconciliation()->run();
$reads = array_filter(array_slice($mock::state()['calls'], $calls_before), static fn (array $call): bool => '/transfer/get' === $call['path'] || '/transfer/intent/get' === $call['path']);
bmfp_assert(array() === array_filter($reads, static fn (array $call): bool => 'sandboxclientb' !== $call['client_id']), 'Reconciliation uses only account B credentials.');
bmfp_assert(array() === array_filter($reads, static fn (array $call): bool => $open_a_transfer === ($call['body']['transfer_id'] ?? '')), 'Account A\'s payment is never queried with account B credentials.');
bmfp_assert(0 < ( new Buckmerce\Plaid\Persistence\PaymentLockStore() )->count_other_account('sandbox', $sandbox_b->account_fp), 'Diagnostics count account A\'s payment as another account\'s.');
$calls_before = count($mock::state()['calls']);
bmfp_assert_same(Buckmerce\Plaid\Payment\OrderSynchronizer::RESULT_SKIPPED, bmfp_ac()->synchronizer()->sync(bmfp_reload($open_a)), '"Sync with Plaid" skips an order of another Plaid account.');
bmfp_assert_same($calls_before, count($mock::state()['calls']), 'No Plaid call with the wrong account\'s credentials.');

bmfp_configure();
WP_CLI::success('Buckmerce account-scope suite passed (HPOS=' . getenv('BUCKMERCE_PLAID_EXPECT_HPOS') . ').');
