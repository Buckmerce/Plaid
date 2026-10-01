<?php

/**
 * Schema 2 → 3 upgrade (ADR-0018) on a store with real history: completed, refunded and
 * returned payments, stored events, the per-environment cursor and payment epoch, locks with
 * account fingerprints and refund rows. No financial history may be lost, no remote object
 * duplicated, the new account-scoped cursor starts safely, and the migration is idempotent.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Refund\RefundState;
use Buckmerce\Plaid\Settings\AccountScope;

global $wpdb;
$mock = Buckmerce_Test_Plaid_Mock::class;
bmfp_configure();
bmfp_reset_world();
$events_table = $wpdb->prefix . 'buckmerce_plaid_events';
$refunds_table = $wpdb->prefix . 'buckmerce_plaid_refunds';
$locks_table = $wpdb->prefix . 'buckmerce_plaid_payment_locks';

function bmfp_mg(): Container
{
    return new Container();
}

/** @return array{WC_Order, string} */
function bmfp_mg_pay(string $total, array $lifecycle): array
{
    $order = bmfp_order($total);
    bmfp_assert('success' === bmfp_gateway()->process_payment($order->get_id())['result'], 'Checkout ' . $total);
    $transfer = Buckmerce_Test_Plaid_Mock::authorize(bmfp_mg()->attempts()->issue_link_token(bmfp_reload($order))->token);
    bmfp_mg()->completion()->complete(bmfp_reload($order));
    foreach ($lifecycle as $type) {
        Buckmerce_Test_Plaid_Mock::add_event($transfer, $type, 'returned' === $type ? 'R01' : '');
    }
    bmfp_mg()->event_sync()->run();
    return array(bmfp_reload($order), $transfer);
}

/** @return array<string, mixed> Everything financial about an order that the upgrade must not change. */
function bmfp_mg_fingerprint(WC_Order $order): array
{
    $order = bmfp_reload($order);
    return array(
        'status' => $order->get_status(),
        'state' => (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true),
        'transfer' => (string) $order->get_meta(OrderMeta::TRANSFER_ID, true),
        'paid' => $order->get_date_paid()?->getTimestamp(),
        'refunded' => $order->get_total_refunded(),
        'notes' => count(bmfp_notes($order)),
        'attempts' => count(\Buckmerce\Plaid\Payment\AttemptHistory::all($order)),
    );
}

// ---------------------------------------------------------------------------------
WP_CLI::log('A schema-2 store with payment, refund and return history');
$scope = bmfp_scope();
[$paid, $paid_transfer] = bmfp_mg_pay('11.11', array('posted', 'settled', 'funds_available'));
[$returned, $returned_transfer] = bmfp_mg_pay('33.33', array('posted', 'settled', 'funds_available', 'returned'));
bmfp_assert(bmfp_reload($paid)->is_paid() && PaymentState::RETURNED === (string) bmfp_reload($returned)->get_meta(OrderMeta::PAYMENT_STATE, true), 'Precondition: a completed and a returned payment.');
bmfp_assert(bmfp_wc_refund(bmfp_reload($paid), '2.00') instanceof WC_Order_Refund && bmfp_wc_refund(bmfp_reload($paid), '3.00') instanceof WC_Order_Refund, 'Precondition: two refunds.');
foreach (bmfp_refund_rows($paid) as $row) {
    $mock::add_refund_event((string) $row['refund_id'], 'refund.posted');
}
bmfp_mg()->event_sync()->run();
// One more event reaches Plaid but is not synchronized before the upgrade.
$mock::add_event($paid_transfer, 'funds_available');

// Rewind the database to the exact schema-2 shape and options.
$wpdb->query("ALTER TABLE {$events_table} DROP INDEX account_event, DROP INDEX scope_status, DROP COLUMN account_fp, ADD UNIQUE KEY environment_event (environment, event_id)");
$wpdb->query("ALTER TABLE {$refunds_table} DROP INDEX account_refund, DROP INDEX account_reconcile, MODIFY account_fp char(16) NULL, ADD UNIQUE KEY environment_refund (environment, refund_id)");
$wpdb->query("ALTER TABLE {$locks_table} DROP INDEX account_state");
$refund_rows = bmfp_refund_rows($paid);
$wpdb->query($wpdb->prepare("UPDATE {$refunds_table} SET account_fp = NULL WHERE id = %d", $refund_rows[1]['id']));
$legacy_cursor = ( new EventCursor() )->get($scope);
$legacy_epoch = PaymentEpoch::get($scope);
bmfp_assert('0' !== $legacy_cursor && null !== $legacy_epoch, 'Precondition: cursor and epoch exist.');
update_option('buckmerce_plaid_event_cursor_sandbox', $legacy_cursor, false);
update_option(PaymentEpoch::OPTION_PREFIX . 'sandbox', (string) $legacy_epoch, false);
delete_option(EventCursor::option_name($scope));
delete_option(PaymentEpoch::option_name($scope));
update_option(Installer::OPTION, '2');
bmfp_assert(! Installer::schema_is_valid(), 'A schema-2 database is detected as outdated (environment-only identities).');

$counts = static fn (): array => array(
    'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$events_table}"),
    'refunds' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$refunds_table}"),
    'locks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$locks_table}"),
);
$before = $counts();
$legacy_events = $wpdb->get_results("SELECT id, event_id, status, order_id, error_code, updated_at FROM {$events_table} ORDER BY id", ARRAY_A);
$locks_before = $wpdb->get_results("SELECT order_id, environment, account_fp, status, transfer_id, payment_state FROM {$locks_table} ORDER BY order_id", ARRAY_A);
$orders_before = array('paid' => bmfp_mg_fingerprint($paid), 'returned' => bmfp_mg_fingerprint($returned));
$remote_before = array(count($mock::calls('/transfer/intent/create')), count($mock::calls('/transfer/refund/create')), count($mock::calls('/link/token/create')));

// ---------------------------------------------------------------------------------
WP_CLI::log('Upgrade to schema 3');
Installer::install();
bmfp_assert(Installer::schema_is_valid() && '3' === get_option(Installer::OPTION), 'Schema 2 → 3 verified.');
bmfp_assert_same($before, $counts(), 'No event, refund or payment row was lost or added.');
bmfp_assert_same(array(AccountScope::LEGACY), $wpdb->get_col("SELECT DISTINCT account_fp FROM {$events_table}"), 'Existing events are kept in the legacy scope (their account cannot be proven).');
bmfp_assert_same($legacy_events, $wpdb->get_results("SELECT id, event_id, status, order_id, error_code, updated_at FROM {$events_table} ORDER BY id", ARRAY_A), 'Event history is untouched.');
bmfp_assert_same($scope->account_fp, (string) $wpdb->get_var($wpdb->prepare("SELECT account_fp FROM {$refunds_table} WHERE id = %d", $refund_rows[0]['id'])), 'A refund keeps its recorded account.');
bmfp_assert_same(AccountScope::LEGACY, (string) $wpdb->get_var($wpdb->prepare("SELECT account_fp FROM {$refunds_table} WHERE id = %d", $refund_rows[1]['id'])), 'A refund of unknown account is kept in the legacy scope.');
bmfp_assert_same($locks_before, $wpdb->get_results("SELECT order_id, environment, account_fp, status, transfer_id, payment_state FROM {$locks_table} ORDER BY order_id", ARRAY_A), 'Payment index rows (with their accounts) are unchanged.');
bmfp_assert_same($legacy_cursor, (string) get_option('buckmerce_plaid_event_cursor_sandbox'), 'The schema-2 cursor is kept for auditing.');
bmfp_assert_same('0', ( new EventCursor() )->get($scope), 'The account-scoped cursor starts at 0 (the schema-2 cursor may belong to several accounts).');
bmfp_assert_same($legacy_epoch, PaymentEpoch::get($scope), 'The account with payments inherits the environment epoch (never later than its own first payment).');
bmfp_assert_same($orders_before, array('paid' => bmfp_mg_fingerprint($paid), 'returned' => bmfp_mg_fingerprint($returned)), 'Orders are unchanged by the upgrade.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Running the migration twice is harmless');
Installer::install();
bmfp_assert(Installer::schema_is_valid() && '3' === get_option(Installer::OPTION), 'Still valid.');
bmfp_assert_same($before, $counts(), 'Still no row lost or added.');
update_option(Installer::OPTION, '2');
Installer::install();
bmfp_assert(Installer::schema_is_valid() && $before === $counts(), 'Re-running every step on an already migrated schema changes nothing.');
bmfp_assert_same($legacy_epoch, PaymentEpoch::get($scope), 'The epoch is never overwritten.');

// ---------------------------------------------------------------------------------
WP_CLI::log('The account stream is re-read from 0: idempotent, nothing duplicated at Plaid or in WooCommerce');
for ($run = 0; $run < 10 && bmfp_mg()->event_sync()->run()['more']; ++$run) {
}
$client_events = count(array_filter($mock::state()['events'], static fn (array $event): bool => $mock::DEFAULT_CLIENT === ($event['client_id'] ?? '')));
bmfp_assert_same($client_events, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE account_fp = %s", $scope->account_fp)), 'Every event of the account is stored once in its scope.');
bmfp_assert_same(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE account_fp = %s AND status IN ('received', 'retry', 'unmatched', 'processing', 'abandoned')", $scope->account_fp)), 'All re-read events were processed.');
bmfp_assert_same($legacy_events, $wpdb->get_results($wpdb->prepare("SELECT id, event_id, status, order_id, error_code, updated_at FROM {$events_table} WHERE account_fp = %s ORDER BY id", AccountScope::LEGACY), ARRAY_A), 'Legacy rows stay untouched for auditing.');
$after = array('paid' => bmfp_mg_fingerprint($paid), 'returned' => bmfp_mg_fingerprint($returned));
bmfp_assert_same($orders_before, $after, 'Replayed history changed no order: no duplicate notes, statuses, payments or refunds.');
bmfp_assert_same($remote_before, array(count($mock::calls('/transfer/intent/create')), count($mock::calls('/transfer/refund/create')), count($mock::calls('/link/token/create'))), 'No remote payment, refund or Link session was created.');
bmfp_assert_same($before['refunds'], $counts()['refunds'], 'No refund was recorded twice (legacy refund rows still match their Plaid refund IDs).');
foreach (bmfp_refund_rows($paid) as $row) {
    bmfp_assert_same(RefundState::POSTED, (string) $row['status'], 'Refund ' . $row['id'] . ' keeps its state.');
}
bmfp_assert_same((string) $mock::last_event_id($mock::DEFAULT_CLIENT), ( new EventCursor() )->get($scope), 'The account cursor caught up.');
bmfp_assert(PaymentState::RETURNED === (string) bmfp_reload($returned)->get_meta(OrderMeta::PAYMENT_STATE, true) && 'failed' === bmfp_reload($returned)->get_status(), 'The returned payment stays returned.');
$reconciled = bmfp_mg()->reconciliation()->run();
bmfp_assert('ok' === $reconciled['status'], 'Reconciliation runs on the migrated schema.');

// The schema-2 fixtures of this suite are not part of the following suites' fresh store.
delete_option('buckmerce_plaid_event_cursor_sandbox');
delete_option(PaymentEpoch::OPTION_PREFIX . 'sandbox');
bmfp_reset_world();
WP_CLI::success('Buckmerce schema migration suite passed (HPOS=' . getenv('BUCKMERCE_PLAID_EXPECT_HPOS') . ').');
