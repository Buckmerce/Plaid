<?php

/**
 * Native WooCommerce refunds through Plaid (ADR-0016), end to end against the Plaid double:
 * wc_create_refund() → process_refund() → /transfer/refund/create → refund events → state.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Refund\RefundState;

global $wpdb;
bmfp_configure();
bmfp_reset_world();
$mock = Buckmerce_Test_Plaid_Mock::class;

function bmfp_rc(): Container
{
    return new Container();
}

/** @return array{WC_Order, string} A payment that reached funds_available. */
function bmfp_paid(string $total, array $lifecycle = array('posted', 'settled', 'funds_available')): array
{
    $order = bmfp_order($total);
    bmfp_gateway()->process_payment($order->get_id());
    $token = bmfp_rc()->attempts()->issue_link_token(bmfp_reload($order));
    bmfp_assert(null !== $token, 'Link token for ' . $total);
    $transfer = Buckmerce_Test_Plaid_Mock::authorize($token->token);
    bmfp_rc()->completion()->complete(bmfp_reload($order));
    foreach ($lifecycle as $type) {
        Buckmerce_Test_Plaid_Mock::add_event($transfer, $type);
    }
    bmfp_rc()->event_sync()->run();
    return array(bmfp_reload($order), $transfer);
}

function bmfp_refund_creates(): int
{
    return count(Buckmerce_Test_Plaid_Mock::calls('/transfer/refund/create'));
}

function bmfp_mock_refunds(string $transfer_id): array
{
    return array_values(array_filter(Buckmerce_Test_Plaid_Mock::state()['refunds'], static fn (array $refund): bool => $refund['transfer_id'] === $transfer_id));
}

function bmfp_alert(WC_Order $order, string $type): bool
{
    foreach ((array) get_option('buckmerce_plaid_payment_alerts', array()) as $alert) {
        if (is_array($alert) && (int) $alert['order_id'] === $order->get_id() && $type === $alert['type']) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Refunds are offered only for settled Plaid payments');
$gateway = bmfp_gateway();
$pending = bmfp_order('40.00');
$gateway->process_payment($pending->get_id());
$pending_transfer = $mock::authorize(bmfp_rc()->attempts()->issue_link_token(bmfp_reload($pending))->token);
bmfp_rc()->completion()->complete(bmfp_reload($pending));
bmfp_assert(! $gateway->can_refund_order(bmfp_reload($pending)), 'A pending ACH debit is not refundable through Plaid.');
$result = bmfp_wc_refund(bmfp_reload($pending), '5.00');
bmfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'not settled'), 'Refund before settlement is refused with a clear reason.');
bmfp_assert_same(0, bmfp_refund_creates(), 'No Plaid refund for an unsettled payment.');
bmfp_assert_same(array(), bmfp_reload($pending)->get_refunds(), 'WooCommerce discarded the refused refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Full refund');
[$full, $full_transfer] = bmfp_paid('25.00');
bmfp_assert(bmfp_reload($full)->is_paid(), 'Precondition: paid.');
bmfp_assert($gateway->can_refund_order($full), 'A funds-available payment is refundable.');
$refund = bmfp_wc_refund($full, '25.00', 'Customer returned the goods');
bmfp_assert($refund instanceof WC_Order_Refund, 'Full refund succeeds: ' . ($refund instanceof WP_Error ? $refund->get_error_message() : ''));
bmfp_assert_same(1, bmfp_refund_creates(), 'Exactly one Plaid refund.');
$call = $mock::calls('/transfer/refund/create')[0]['body'];
bmfp_assert_same($full_transfer, $call['transfer_id'], 'Refund targets the order transfer.');
bmfp_assert_same('25.00', $call['amount'], 'Refund amount is the WooCommerce refund amount.');
bmfp_assert(1 === preg_match('/^bmfp-[a-f0-9]{44}$/', $call['idempotency_key']), 'Deterministic idempotency key ≤ 50 characters.');
$rows = bmfp_refund_rows($full);
bmfp_assert(1 === count($rows) && RefundState::PENDING === $rows[0]['status'] && '' !== (string) $rows[0]['refund_id'], 'Refund row pending with Plaid ID.');
bmfp_assert_same((string) $refund->get_id(), (string) $rows[0]['wc_refund_id'], 'Bound to the WooCommerce refund.');
bmfp_assert_same((string) $rows[0]['refund_id'], (string) wc_get_order($refund->get_id())->get_meta('_bmfp_refund_id', true), 'WooCommerce refund carries the Plaid refund ID.');
bmfp_assert(wc_get_order($refund->get_id())->get_refunded_payment(), 'WooCommerce records the refund as paid by the gateway.');
bmfp_assert_same('refunded', bmfp_reload($full)->get_status(), 'Full refund → WooCommerce Refunded.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($full), 'refund of $25.00 submitted to Plaid'), 'Private refund note.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($full), 'can still be returned'), 'Refund inside the return window carries the double-loss warning.');
bmfp_assert(! $gateway->can_refund_order(bmfp_reload($full)), 'Fully refunded.');

// Refund lifecycle through verified events; duplicates and out-of-order are harmless.
$refund_id = (string) $rows[0]['refund_id'];
$mock::add_refund_event($refund_id, 'refund.posted');
$mock::add_refund_event($refund_id, 'refund.settled');
bmfp_rc()->event_sync()->run();
bmfp_assert_same(RefundState::SETTLED, bmfp_refund_rows($full)[0]['status'], 'Refund events drive the refund state.');
bmfp_assert_same(PaymentState::FUNDS_AVAILABLE, (string) bmfp_reload($full)->get_meta(OrderMeta::PAYMENT_STATE, true), 'Refund events never change the payment state.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_events SET status = 'received', attempts = 0 WHERE transfer_id = %s", $full_transfer));
bmfp_rc()->event_sync()->run();
bmfp_assert_same(1, bmfp_note_count(bmfp_reload($full), 'settled at the customer'), 'Replayed refund events add nothing.');
$mock::add_refund_event($refund_id, 'refund.posted');
$state = $mock::state();
$state['events'][count($state['events']) - 1]['event_type'] = 'refund.posted';
Buckmerce_Test_Plaid_Mock::save($state);
bmfp_rc()->event_sync()->run();
bmfp_assert_same(RefundState::SETTLED, bmfp_refund_rows($full)[0]['status'], 'A late refund.posted never regresses a settled refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Multiple partial refunds, limits and double submits');
[$partial, $partial_transfer] = bmfp_paid('100.00');
bmfp_assert(bmfp_wc_refund($partial, '30.00') instanceof WC_Order_Refund, 'First partial refund.');
$duplicate = bmfp_wc_refund(bmfp_reload($partial), '30.00');
bmfp_assert($duplicate instanceof WP_Error && str_contains($duplicate->get_error_message(), 'identical refund'), 'An identical refund within a minute is refused as a double submit.');
bmfp_assert(bmfp_wc_refund(bmfp_reload($partial), '20.00') instanceof WC_Order_Refund, 'A different partial refund is allowed.');
bmfp_assert_same(2, count(bmfp_mock_refunds($partial_transfer)), 'Two Plaid refunds.');
bmfp_assert_same(2, count(bmfp_reload($partial)->get_refunds()), 'The refused double submit left no WooCommerce refund behind.');
$eligibility = bmfp_rc()->refunds()->eligibility(bmfp_reload($partial));
bmfp_assert(true === $eligibility->allowed && '50.00' === $eligibility->remaining && '50.00' === $eligibility->refunded, 'Remaining refundable amount is exact.');
// Buckmerce's limit also covers refunds WooCommerce does not know about (e.g. created in the Plaid Dashboard).
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 300), $partial->get_id()));
$wpdb->insert($wpdb->prefix . 'buckmerce_plaid_refunds', array('order_id' => $partial->get_id(), 'environment' => 'sandbox', 'attempt_id' => '', 'transfer_id' => $partial_transfer, 'refund_id' => 'dashboard-refund', 'idempotency_key' => 'ext-dashboard-refund', 'amount' => '45.00', 'currency' => 'USD', 'status' => 'settled', 'origin' => 'external', 'checks' => 0, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')));
$creates_before = bmfp_refund_creates();
$over = bmfp_wc_refund(bmfp_reload($partial), '10.00');
bmfp_assert($over instanceof WP_Error && str_contains($over->get_error_message(), '$5.00'), 'Refunds never exceed the safely refundable amount (5.00 left).');
bmfp_assert_same($creates_before, bmfp_refund_creates(), 'No Plaid call for an over-refund.');
bmfp_assert(bmfp_wc_refund(bmfp_reload($partial), '5.00') instanceof WC_Order_Refund, 'The exact remainder can be refunded.');
bmfp_assert(! $gateway->can_refund_order(bmfp_reload($partial)), 'Nothing left to refund.');

// process_refund retried for the same WooCommerce refund never creates a second refund.
[$retry_order] = bmfp_paid('12.00');
$wc_refund = bmfp_wc_refund($retry_order, '4.00');
bmfp_assert($wc_refund instanceof WC_Order_Refund, 'Refund for retry test.');
$creates_before = bmfp_refund_creates();
Buckmerce\Plaid\Refund\WooRefundContext::capture($wc_refund);
$again = bmfp_rc()->refunds()->refund(bmfp_reload($retry_order), $wc_refund, '4.00');
bmfp_assert($again->ok, 'The retried refund reports the existing refund.');
bmfp_assert_same($creates_before, bmfp_refund_creates(), 'No second Plaid call for the same WooCommerce refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Refund failure ($2.22) and refund return ($1.11) from Plaid Sandbox simulations');
delete_option('bmfp_test_mails');
[$failing] = bmfp_paid('30.00');
bmfp_assert(bmfp_wc_refund($failing, '2.22') instanceof WC_Order_Refund, 'Refund accepted by Plaid.');
bmfp_rc()->event_sync()->run();
$row = bmfp_refund_rows($failing)[0];
bmfp_assert_same(RefundState::FAILED, $row['status'], 'Refund failed.');
bmfp_assert(bmfp_alert($failing, 'refund_failed'), 'Refund failure alert.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($failing), 'REFUND FAILED'), 'High-visibility note.');
bmfp_assert_same('failed', (string) wc_get_order((int) $row['wc_refund_id'])->get_meta('_bmfp_refund_status', true), 'WooCommerce refund marked failed.');
$mails = get_option('bmfp_test_mails');
bmfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $mail): bool => str_contains((string) $mail['subject'], 'Refund failed'))), 'Merchant emailed about the failed refund.');
bmfp_assert('30.00' === bmfp_rc()->refunds()->eligibility(bmfp_reload($failing))->remaining, 'A failed refund does not consume the refundable amount.');

[$bounced] = bmfp_paid('30.00');
bmfp_assert(bmfp_wc_refund($bounced, '1.11') instanceof WC_Order_Refund, 'Refund accepted.');
bmfp_rc()->event_sync()->run();
bmfp_assert_same(RefundState::RETURNED, bmfp_refund_rows($bounced)[0]['status'], 'pending → posted → settled → returned.');
bmfp_assert(bmfp_alert($bounced, 'refund_returned'), 'Returned refund alert.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Ambiguous refund creation never creates a second refund');
// Response lost after Plaid created the refund: the same key is retried and Plaid returns the same refund.
[$lost] = bmfp_paid('40.00');
$mock::fail_next('/transfer/refund/create', 'timeout_after_create');
$result = bmfp_wc_refund($lost, '10.00');
bmfp_assert($result instanceof WC_Order_Refund, 'Retry with the same idempotency key recovers the refund.');
$lost_transfer = (string) bmfp_reload($lost)->get_meta(OrderMeta::TRANSFER_ID, true);
bmfp_assert_same(1, count(bmfp_mock_refunds($lost_transfer)), 'Exactly one Plaid refund exists.');
$keys = array_map(static fn (array $call): string => (string) $call['body']['idempotency_key'], array_values(array_filter($mock::calls('/transfer/refund/create'), static fn (array $call): bool => $call['body']['transfer_id'] === $lost_transfer)));
bmfp_assert(2 === count($keys) && $keys[0] === $keys[1], 'Both attempts used the same idempotency key.');

// 502 after creation, then the retry succeeds through idempotency.
[$bad_gateway] = bmfp_paid('40.00');
$mock::fail_next('/transfer/refund/create', 'server_error_after_create');
bmfp_assert(bmfp_wc_refund($bad_gateway, '10.00') instanceof WC_Order_Refund, '502 after remote creation is recovered.');
bmfp_assert_same(1, count(bmfp_mock_refunds((string) bmfp_reload($bad_gateway)->get_meta(OrderMeta::TRANSFER_ID, true))), 'Still exactly one refund.');

// Unknown outcome twice: the refund is left uncertain, blocks further refunds and is resolved from Plaid.
[$unknown] = bmfp_paid('40.00');
$unknown_transfer = (string) bmfp_reload($unknown)->get_meta(OrderMeta::TRANSFER_ID, true);
$mock::fail_next('/transfer/refund/create', 'timeout');
$mock::fail_next('/transfer/refund/create', 'timeout');
$result = bmfp_wc_refund($unknown, '10.00');
bmfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'did not confirm'), 'Unknown outcome is reported, never as success.');
bmfp_assert_same(RefundState::UNCERTAIN, bmfp_refund_rows($unknown)[0]['status'], 'Refund uncertain.');
bmfp_assert(bmfp_alert($unknown, 'refund_uncertain'), 'Uncertain refund alert.');
$blocked = bmfp_wc_refund(bmfp_reload($unknown), '5.00');
bmfp_assert($blocked instanceof WP_Error && str_contains($blocked->get_error_message(), 'still being confirmed'), 'No further refunds while one is unconfirmed.');
bmfp_assert_same(0, count(bmfp_mock_refunds($unknown_transfer)), 'Plaid never created it.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET created_at = %s, reconcile_after = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() - 60), $unknown->get_id()));
$creates_before = bmfp_refund_creates();
bmfp_rc()->refunds()->reconcile_due(10);
bmfp_assert_same(RefundState::VOID, bmfp_refund_rows($unknown)[0]['status'], 'Proven not created → void.');
bmfp_assert_same($creates_before, bmfp_refund_creates(), 'Resolution never re-sends the create.');
bmfp_assert(! bmfp_alert($unknown, 'refund_uncertain'), 'Resolved uncertainty clears its alert.');
bmfp_assert(bmfp_wc_refund(bmfp_reload($unknown), '10.00') instanceof WC_Order_Refund, 'After void the merchant can refund again.');

// Created at Plaid, response lost twice and the immediate lookup failed: the refund event adopts it.
[$adopted] = bmfp_paid('40.00');
$adopted_transfer = (string) bmfp_reload($adopted)->get_meta(OrderMeta::TRANSFER_ID, true);
$mock::fail_next('/transfer/refund/create', 'timeout_after_create');
$mock::fail_next('/transfer/refund/create', 'timeout');
$mock::fail_next('/transfer/get', 'server_error');
$result = bmfp_wc_refund($adopted, '15.00');
bmfp_assert($result instanceof WP_Error, 'Unconfirmed refund reported as error.');
bmfp_assert_same(array(), bmfp_reload($adopted)->get_refunds(), 'WooCommerce discarded its refund record.');
bmfp_assert_same(1, count(bmfp_mock_refunds($adopted_transfer)), 'But Plaid created the refund.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET lease_expires_at = NULL WHERE order_id = %d", $adopted->get_id()));
bmfp_rc()->event_sync()->run();
$row = bmfp_refund_rows($adopted)[0];
bmfp_assert(RefundState::PENDING === $row['status'] && bmfp_mock_refunds($adopted_transfer)[0]['id'] === $row['refund_id'], 'The refund event adopted the unconfirmed refund.');
$restored = bmfp_reload($adopted)->get_refunds();
bmfp_assert(1 === count($restored) && '15' === rtrim(rtrim((string) $restored[0]->get_amount(), '0'), '.') && (int) $row['wc_refund_id'] === $restored[0]->get_id(), 'The WooCommerce refund record was restored and linked.');
bmfp_assert(1 === bmfp_note_count(bmfp_reload($adopted), 'confirmed the refund'), 'Adoption is noted.');

// Remote refund success + local persistence failure: never recorded twice, recovered by reconciliation.
[$crashed] = bmfp_paid('40.00');
$crashed_transfer = (string) bmfp_reload($crashed)->get_meta(OrderMeta::TRANSFER_ID, true);
$break = static fn (string $query): string => (str_contains($query, 'buckmerce_plaid_refunds') && str_contains($query, 'SET refund_id')) ? 'SELECT * FROM bmfp_missing_table_for_failure_injection' : $query;
add_filter('query', $break);
$suppress = $wpdb->suppress_errors(true);
$result = bmfp_wc_refund($crashed, '12.00');
$wpdb->suppress_errors($suppress);
remove_filter('query', $break);
bmfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'could not be recorded'), 'Local write failure after Plaid success is reported, not hidden.');
bmfp_assert_same(1, count(bmfp_mock_refunds($crashed_transfer)), 'One refund at Plaid.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET lease_expires_at = %s, reconcile_after = %s, created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() - 400), $crashed->get_id()));
$creates_before = bmfp_refund_creates();
bmfp_rc()->refunds()->reconcile_due(10);
$row = bmfp_refund_rows($crashed)[0];
bmfp_assert(RefundState::PENDING === $row['status'] && '' !== (string) $row['refund_id'], 'Reconciliation adopted the refund from Plaid\'s refund list.');
bmfp_assert_same($creates_before, bmfp_refund_creates(), 'No new create call during recovery.');
bmfp_assert_same(1, count(bmfp_reload($crashed)->get_refunds()), 'Exactly one WooCommerce refund record.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Missed refund events are recovered by reconciliation (/transfer/refund/get)');
[$quiet] = bmfp_paid('20.00');
bmfp_assert(bmfp_wc_refund($quiet, '8.00') instanceof WC_Order_Refund, 'Refund created.');
$quiet_refund = (string) bmfp_refund_rows($quiet)[0]['refund_id'];
$state = $mock::state();
$state['refunds'][$quiet_refund]['status'] = 'settled';
Buckmerce_Test_Plaid_Mock::save($state);
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET reconcile_after = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 1), $quiet->get_id()));
bmfp_rc()->refunds()->reconcile_due(10);
bmfp_assert_same(RefundState::SETTLED, bmfp_refund_rows($quiet)[0]['status'], 'Polling recovered the missed refund events.');
$row = bmfp_refund_rows($quiet)[0];
bmfp_assert('' !== (string) $row['reconcile_after'] && '' !== (string) $row['monitor_until'], 'A settled refund stays monitored for returns for a few days.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Refunds created outside WooCommerce are recorded and counted');
[$dashboard, $dashboard_transfer] = bmfp_paid('50.00');
$state = $mock::state();
$state['refunds']['dash-1'] = array('id' => 'dash-1', 'transfer_id' => $dashboard_transfer, 'amount' => '20.00', 'status' => 'pending', 'failure_reason' => null, 'ledger_id' => 'l', 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'network_trace_id' => null);
Buckmerce_Test_Plaid_Mock::save($state);
$mock::add_refund_event('dash-1', 'refund.pending');
bmfp_rc()->event_sync()->run();
$rows = bmfp_refund_rows($dashboard);
bmfp_assert(1 === count($rows) && 'external' === $rows[0]['origin'] && 'dash-1' === $rows[0]['refund_id'], 'External refund recorded.');
bmfp_assert(bmfp_alert($dashboard, 'external_refund'), 'Merchant alerted to record it.');
bmfp_assert_same('30.00', bmfp_rc()->refunds()->eligibility(bmfp_reload($dashboard))->remaining, 'External refunds reduce the refundable amount.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Original debit returned after a refund: impossible to overlook');
delete_option('bmfp_test_mails');
[$double, $double_transfer] = bmfp_paid('60.00', array('posted', 'settled', 'funds_available'));
bmfp_assert(bmfp_wc_refund($double, '20.00') instanceof WC_Order_Refund, 'First refund.');
$first_refund = (string) bmfp_refund_rows($double)[0]['refund_id'];
$mock::add_refund_event($first_refund, 'refund.posted');
$mock::add_refund_event($first_refund, 'refund.settled');
bmfp_rc()->event_sync()->run();
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_refunds SET created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 300), $double->get_id()));
bmfp_assert(bmfp_wc_refund(bmfp_reload($double), '15.00') instanceof WC_Order_Refund, 'Second refund, still pending at Plaid.');
$second_refund = (string) bmfp_refund_rows($double)[1]['refund_id'];
$mock::add_event($double_transfer, 'returned', 'R10');
bmfp_rc()->event_sync()->run();
$double = bmfp_reload($double);
bmfp_assert_same(PaymentState::RETURNED, (string) $double->get_meta(OrderMeta::PAYMENT_STATE, true), 'The return is recorded.');
bmfp_assert(bmfp_alert($double, 'returned_after_refund'), 'Critical return-after-refund alert.');
bmfp_assert(! bmfp_alert($double, 'returned'), 'Not normalized into a plain return alert.');
bmfp_assert(1 === bmfp_note_count($double, 'CRITICAL'), 'High-severity private note.');
$critical = array_values(array_filter(bmfp_notes($double), static fn (string $note): bool => str_contains($note, 'CRITICAL')));
bmfp_assert(str_contains($critical[0], '$20.00') && ! str_contains($critical[0], '$35.00'), 'The note states the refunded amount that left the merchant (the cancelled refund is excluded).');
$mails = get_option('bmfp_test_mails');
bmfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $mail): bool => str_contains((string) $mail['subject'], 'URGENT: ACH return after refund'))), 'Urgent merchant email.');
bmfp_assert_same('cancelled', $mock::state()['refunds'][$second_refund]['status'], 'The still-pending refund was cancelled at Plaid (the customer already has the money back).');
bmfp_assert_same(RefundState::CANCELLED, bmfp_refund_rows($double)[1]['status'], 'Cancellation recorded.');
bmfp_assert_same(RefundState::SETTLED, bmfp_refund_rows($double)[0]['status'], 'The settled refund is kept as history.');
bmfp_assert(null !== $double->get_date_paid() && $double_transfer === $double->get_transaction_id(), 'Original payment history preserved.');
$blocked = bmfp_wc_refund($double, '1.00');
bmfp_assert($blocked instanceof WP_Error && str_contains($blocked->get_error_message(), 'returned'), 'A returned payment cannot be refunded again.');

WP_CLI::success('Buckmerce refund suite passed (HPOS=' . (getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ?: '?') . ').');
