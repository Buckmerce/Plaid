<?php

/**
 * Native WooCommerce refunds through Plaid (ADR-0016), end to end against the Plaid double:
 * wc_create_refund() → process_refund() → /transfer/refund/create → refund events → state.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Refund\RefundState;

global $wpdb;
pbfp_configure();
pbfp_reset_world();
$mock = PayBridge_Test_Plaid_Mock::class;

function pbfp_rc(): Container
{
    return new Container();
}

/** @return array{WC_Order, string} A payment that reached funds_available. */
function pbfp_paid(string $total, array $lifecycle = array('posted', 'settled', 'funds_available')): array
{
    $order = pbfp_order($total);
    pbfp_gateway()->process_payment($order->get_id());
    $token = pbfp_rc()->attempts()->issue_link_token(pbfp_reload($order));
    pbfp_assert(null !== $token, 'Link token for ' . $total);
    $transfer = PayBridge_Test_Plaid_Mock::authorize($token->token);
    pbfp_rc()->completion()->complete(pbfp_reload($order));
    foreach ($lifecycle as $type) {
        PayBridge_Test_Plaid_Mock::add_event($transfer, $type);
    }
    pbfp_rc()->event_sync()->run();
    return array(pbfp_reload($order), $transfer);
}

function pbfp_refund_creates(): int
{
    return count(PayBridge_Test_Plaid_Mock::calls('/transfer/refund/create'));
}

function pbfp_mock_refunds(string $transfer_id): array
{
    return array_values(array_filter(PayBridge_Test_Plaid_Mock::state()['refunds'], static fn (array $refund): bool => $refund['transfer_id'] === $transfer_id));
}

function pbfp_alert(WC_Order $order, string $type): bool
{
    foreach ((array) get_option('paybridge_plaid_payment_alerts', array()) as $alert) {
        if (is_array($alert) && (int) $alert['order_id'] === $order->get_id() && $type === $alert['type']) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Refunds are offered only for settled Plaid payments');
$gateway = pbfp_gateway();
$pending = pbfp_order('40.00');
$gateway->process_payment($pending->get_id());
$pending_transfer = $mock::authorize(pbfp_rc()->attempts()->issue_link_token(pbfp_reload($pending))->token);
pbfp_rc()->completion()->complete(pbfp_reload($pending));
pbfp_assert(! $gateway->can_refund_order(pbfp_reload($pending)), 'A pending ACH debit is not refundable through Plaid.');
$result = pbfp_wc_refund(pbfp_reload($pending), '5.00');
pbfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'not settled'), 'Refund before settlement is refused with a clear reason.');
pbfp_assert_same(0, pbfp_refund_creates(), 'No Plaid refund for an unsettled payment.');
pbfp_assert_same(array(), pbfp_reload($pending)->get_refunds(), 'WooCommerce discarded the refused refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Full refund');
[$full, $full_transfer] = pbfp_paid('25.00');
pbfp_assert(pbfp_reload($full)->is_paid(), 'Precondition: paid.');
pbfp_assert($gateway->can_refund_order($full), 'A funds-available payment is refundable.');
$refund = pbfp_wc_refund($full, '25.00', 'Customer returned the goods');
pbfp_assert($refund instanceof WC_Order_Refund, 'Full refund succeeds: ' . ($refund instanceof WP_Error ? $refund->get_error_message() : ''));
pbfp_assert_same(1, pbfp_refund_creates(), 'Exactly one Plaid refund.');
$call = $mock::calls('/transfer/refund/create')[0]['body'];
pbfp_assert_same($full_transfer, $call['transfer_id'], 'Refund targets the order transfer.');
pbfp_assert_same('25.00', $call['amount'], 'Refund amount is the WooCommerce refund amount.');
pbfp_assert(1 === preg_match('/^pbfp-[a-f0-9]{44}$/', $call['idempotency_key']), 'Deterministic idempotency key ≤ 50 characters.');
$rows = pbfp_refund_rows($full);
pbfp_assert(1 === count($rows) && RefundState::PENDING === $rows[0]['status'] && '' !== (string) $rows[0]['refund_id'], 'Refund row pending with Plaid ID.');
pbfp_assert_same((string) $refund->get_id(), (string) $rows[0]['wc_refund_id'], 'Bound to the WooCommerce refund.');
pbfp_assert_same((string) $rows[0]['refund_id'], (string) wc_get_order($refund->get_id())->get_meta('_pbfp_refund_id', true), 'WooCommerce refund carries the Plaid refund ID.');
pbfp_assert(wc_get_order($refund->get_id())->get_refunded_payment(), 'WooCommerce records the refund as paid by the gateway.');
pbfp_assert_same('refunded', pbfp_reload($full)->get_status(), 'Full refund → WooCommerce Refunded.');
pbfp_assert(1 === pbfp_note_count(pbfp_reload($full), 'refund of $25.00 submitted to Plaid'), 'Private refund note.');
pbfp_assert(1 === pbfp_note_count(pbfp_reload($full), 'can still be returned'), 'Refund inside the return window carries the double-loss warning.');
pbfp_assert(! $gateway->can_refund_order(pbfp_reload($full)), 'Fully refunded.');

// Refund lifecycle through verified events; duplicates and out-of-order are harmless.
$refund_id = (string) $rows[0]['refund_id'];
$mock::add_refund_event($refund_id, 'refund.posted');
$mock::add_refund_event($refund_id, 'refund.settled');
pbfp_rc()->event_sync()->run();
pbfp_assert_same(RefundState::SETTLED, pbfp_refund_rows($full)[0]['status'], 'Refund events drive the refund state.');
pbfp_assert_same(PaymentState::FUNDS_AVAILABLE, (string) pbfp_reload($full)->get_meta(OrderMeta::PAYMENT_STATE, true), 'Refund events never change the payment state.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_events SET status = 'received', attempts = 0 WHERE transfer_id = %s", $full_transfer));
pbfp_rc()->event_sync()->run();
pbfp_assert_same(1, pbfp_note_count(pbfp_reload($full), 'settled at the customer'), 'Replayed refund events add nothing.');
$mock::add_refund_event($refund_id, 'refund.posted');
$state = $mock::state();
$state['events'][count($state['events']) - 1]['event_type'] = 'refund.posted';
PayBridge_Test_Plaid_Mock::save($state);
pbfp_rc()->event_sync()->run();
pbfp_assert_same(RefundState::SETTLED, pbfp_refund_rows($full)[0]['status'], 'A late refund.posted never regresses a settled refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Multiple partial refunds, limits and double submits');
[$partial, $partial_transfer] = pbfp_paid('100.00');
pbfp_assert(pbfp_wc_refund($partial, '30.00') instanceof WC_Order_Refund, 'First partial refund.');
$duplicate = pbfp_wc_refund(pbfp_reload($partial), '30.00');
pbfp_assert($duplicate instanceof WP_Error && str_contains($duplicate->get_error_message(), 'identical refund'), 'An identical refund within a minute is refused as a double submit.');
pbfp_assert(pbfp_wc_refund(pbfp_reload($partial), '20.00') instanceof WC_Order_Refund, 'A different partial refund is allowed.');
pbfp_assert_same(2, count(pbfp_mock_refunds($partial_transfer)), 'Two Plaid refunds.');
pbfp_assert_same(2, count(pbfp_reload($partial)->get_refunds()), 'The refused double submit left no WooCommerce refund behind.');
$eligibility = pbfp_rc()->refunds()->eligibility(pbfp_reload($partial));
pbfp_assert(true === $eligibility->allowed && '50.00' === $eligibility->remaining && '50.00' === $eligibility->refunded, 'Remaining refundable amount is exact.');
// PayBridge's limit also covers refunds WooCommerce does not know about (e.g. created in the Plaid Dashboard).
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 300), $partial->get_id()));
$wpdb->insert($wpdb->prefix . 'paybridge_plaid_refunds', array('order_id' => $partial->get_id(), 'environment' => 'sandbox', 'attempt_id' => '', 'transfer_id' => $partial_transfer, 'refund_id' => 'dashboard-refund', 'idempotency_key' => 'ext-dashboard-refund', 'amount' => '45.00', 'currency' => 'USD', 'status' => 'settled', 'origin' => 'external', 'checks' => 0, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')));
$creates_before = pbfp_refund_creates();
$over = pbfp_wc_refund(pbfp_reload($partial), '10.00');
pbfp_assert($over instanceof WP_Error && str_contains($over->get_error_message(), '$5.00'), 'Refunds never exceed the safely refundable amount (5.00 left).');
pbfp_assert_same($creates_before, pbfp_refund_creates(), 'No Plaid call for an over-refund.');
pbfp_assert(pbfp_wc_refund(pbfp_reload($partial), '5.00') instanceof WC_Order_Refund, 'The exact remainder can be refunded.');
pbfp_assert(! $gateway->can_refund_order(pbfp_reload($partial)), 'Nothing left to refund.');

// process_refund retried for the same WooCommerce refund never creates a second refund.
[$retry_order] = pbfp_paid('12.00');
$wc_refund = pbfp_wc_refund($retry_order, '4.00');
pbfp_assert($wc_refund instanceof WC_Order_Refund, 'Refund for retry test.');
$creates_before = pbfp_refund_creates();
PayBridge\Plaid\Refund\WooRefundContext::capture($wc_refund);
$again = pbfp_rc()->refunds()->refund(pbfp_reload($retry_order), $wc_refund, '4.00');
pbfp_assert($again->ok, 'The retried refund reports the existing refund.');
pbfp_assert_same($creates_before, pbfp_refund_creates(), 'No second Plaid call for the same WooCommerce refund.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Refund failure ($2.22) and refund return ($1.11) from Plaid Sandbox simulations');
delete_option('pbfp_test_mails');
[$failing] = pbfp_paid('30.00');
pbfp_assert(pbfp_wc_refund($failing, '2.22') instanceof WC_Order_Refund, 'Refund accepted by Plaid.');
pbfp_rc()->event_sync()->run();
$row = pbfp_refund_rows($failing)[0];
pbfp_assert_same(RefundState::FAILED, $row['status'], 'Refund failed.');
pbfp_assert(pbfp_alert($failing, 'refund_failed'), 'Refund failure alert.');
pbfp_assert(1 === pbfp_note_count(pbfp_reload($failing), 'REFUND FAILED'), 'High-visibility note.');
pbfp_assert_same('failed', (string) wc_get_order((int) $row['wc_refund_id'])->get_meta('_pbfp_refund_status', true), 'WooCommerce refund marked failed.');
$mails = get_option('pbfp_test_mails');
pbfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $mail): bool => str_contains((string) $mail['subject'], 'Refund failed'))), 'Merchant emailed about the failed refund.');
pbfp_assert('30.00' === pbfp_rc()->refunds()->eligibility(pbfp_reload($failing))->remaining, 'A failed refund does not consume the refundable amount.');

[$bounced] = pbfp_paid('30.00');
pbfp_assert(pbfp_wc_refund($bounced, '1.11') instanceof WC_Order_Refund, 'Refund accepted.');
pbfp_rc()->event_sync()->run();
pbfp_assert_same(RefundState::RETURNED, pbfp_refund_rows($bounced)[0]['status'], 'pending → posted → settled → returned.');
pbfp_assert(pbfp_alert($bounced, 'refund_returned'), 'Returned refund alert.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Ambiguous refund creation never creates a second refund');
// Response lost after Plaid created the refund: the same key is retried and Plaid returns the same refund.
[$lost] = pbfp_paid('40.00');
$mock::fail_next('/transfer/refund/create', 'timeout_after_create');
$result = pbfp_wc_refund($lost, '10.00');
pbfp_assert($result instanceof WC_Order_Refund, 'Retry with the same idempotency key recovers the refund.');
$lost_transfer = (string) pbfp_reload($lost)->get_meta(OrderMeta::TRANSFER_ID, true);
pbfp_assert_same(1, count(pbfp_mock_refunds($lost_transfer)), 'Exactly one Plaid refund exists.');
$keys = array_map(static fn (array $call): string => (string) $call['body']['idempotency_key'], array_values(array_filter($mock::calls('/transfer/refund/create'), static fn (array $call): bool => $call['body']['transfer_id'] === $lost_transfer)));
pbfp_assert(2 === count($keys) && $keys[0] === $keys[1], 'Both attempts used the same idempotency key.');

// 502 after creation, then the retry succeeds through idempotency.
[$bad_gateway] = pbfp_paid('40.00');
$mock::fail_next('/transfer/refund/create', 'server_error_after_create');
pbfp_assert(pbfp_wc_refund($bad_gateway, '10.00') instanceof WC_Order_Refund, '502 after remote creation is recovered.');
pbfp_assert_same(1, count(pbfp_mock_refunds((string) pbfp_reload($bad_gateway)->get_meta(OrderMeta::TRANSFER_ID, true))), 'Still exactly one refund.');

// Unknown outcome twice: the refund is left uncertain, blocks further refunds and is resolved from Plaid.
[$unknown] = pbfp_paid('40.00');
$unknown_transfer = (string) pbfp_reload($unknown)->get_meta(OrderMeta::TRANSFER_ID, true);
$mock::fail_next('/transfer/refund/create', 'timeout');
$mock::fail_next('/transfer/refund/create', 'timeout');
$result = pbfp_wc_refund($unknown, '10.00');
pbfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'did not confirm'), 'Unknown outcome is reported, never as success.');
pbfp_assert_same(RefundState::UNCERTAIN, pbfp_refund_rows($unknown)[0]['status'], 'Refund uncertain.');
pbfp_assert(pbfp_alert($unknown, 'refund_uncertain'), 'Uncertain refund alert.');
$blocked = pbfp_wc_refund(pbfp_reload($unknown), '5.00');
pbfp_assert($blocked instanceof WP_Error && str_contains($blocked->get_error_message(), 'still being confirmed'), 'No further refunds while one is unconfirmed.');
pbfp_assert_same(0, count(pbfp_mock_refunds($unknown_transfer)), 'Plaid never created it.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET created_at = %s, reconcile_after = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() - 60), $unknown->get_id()));
$creates_before = pbfp_refund_creates();
pbfp_rc()->refunds()->reconcile_due(10);
pbfp_assert_same(RefundState::VOID, pbfp_refund_rows($unknown)[0]['status'], 'Proven not created → void.');
pbfp_assert_same($creates_before, pbfp_refund_creates(), 'Resolution never re-sends the create.');
pbfp_assert(! pbfp_alert($unknown, 'refund_uncertain'), 'Resolved uncertainty clears its alert.');
pbfp_assert(pbfp_wc_refund(pbfp_reload($unknown), '10.00') instanceof WC_Order_Refund, 'After void the merchant can refund again.');

// Created at Plaid, response lost twice and the immediate lookup failed: the refund event adopts it.
[$adopted] = pbfp_paid('40.00');
$adopted_transfer = (string) pbfp_reload($adopted)->get_meta(OrderMeta::TRANSFER_ID, true);
$mock::fail_next('/transfer/refund/create', 'timeout_after_create');
$mock::fail_next('/transfer/refund/create', 'timeout');
$mock::fail_next('/transfer/get', 'server_error');
$result = pbfp_wc_refund($adopted, '15.00');
pbfp_assert($result instanceof WP_Error, 'Unconfirmed refund reported as error.');
pbfp_assert_same(array(), pbfp_reload($adopted)->get_refunds(), 'WooCommerce discarded its refund record.');
pbfp_assert_same(1, count(pbfp_mock_refunds($adopted_transfer)), 'But Plaid created the refund.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET lease_expires_at = NULL WHERE order_id = %d", $adopted->get_id()));
pbfp_rc()->event_sync()->run();
$row = pbfp_refund_rows($adopted)[0];
pbfp_assert(RefundState::PENDING === $row['status'] && pbfp_mock_refunds($adopted_transfer)[0]['id'] === $row['refund_id'], 'The refund event adopted the unconfirmed refund.');
$restored = pbfp_reload($adopted)->get_refunds();
pbfp_assert(1 === count($restored) && '15' === rtrim(rtrim((string) $restored[0]->get_amount(), '0'), '.') && (int) $row['wc_refund_id'] === $restored[0]->get_id(), 'The WooCommerce refund record was restored and linked.');
pbfp_assert(1 === pbfp_note_count(pbfp_reload($adopted), 'confirmed the refund'), 'Adoption is noted.');

// Remote refund success + local persistence failure: never recorded twice, recovered by reconciliation.
[$crashed] = pbfp_paid('40.00');
$crashed_transfer = (string) pbfp_reload($crashed)->get_meta(OrderMeta::TRANSFER_ID, true);
$break = static fn (string $query): string => (str_contains($query, 'paybridge_plaid_refunds') && str_contains($query, 'SET refund_id')) ? 'SELECT * FROM pbfp_missing_table_for_failure_injection' : $query;
add_filter('query', $break);
$suppress = $wpdb->suppress_errors(true);
$result = pbfp_wc_refund($crashed, '12.00');
$wpdb->suppress_errors($suppress);
remove_filter('query', $break);
pbfp_assert($result instanceof WP_Error && str_contains($result->get_error_message(), 'could not be recorded'), 'Local write failure after Plaid success is reported, not hidden.');
pbfp_assert_same(1, count(pbfp_mock_refunds($crashed_transfer)), 'One refund at Plaid.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET lease_expires_at = %s, reconcile_after = %s, created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() - 400), $crashed->get_id()));
$creates_before = pbfp_refund_creates();
pbfp_rc()->refunds()->reconcile_due(10);
$row = pbfp_refund_rows($crashed)[0];
pbfp_assert(RefundState::PENDING === $row['status'] && '' !== (string) $row['refund_id'], 'Reconciliation adopted the refund from Plaid\'s refund list.');
pbfp_assert_same($creates_before, pbfp_refund_creates(), 'No new create call during recovery.');
pbfp_assert_same(1, count(pbfp_reload($crashed)->get_refunds()), 'Exactly one WooCommerce refund record.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Missed refund events are recovered by reconciliation (/transfer/refund/get)');
[$quiet] = pbfp_paid('20.00');
pbfp_assert(pbfp_wc_refund($quiet, '8.00') instanceof WC_Order_Refund, 'Refund created.');
$quiet_refund = (string) pbfp_refund_rows($quiet)[0]['refund_id'];
$state = $mock::state();
$state['refunds'][$quiet_refund]['status'] = 'settled';
PayBridge_Test_Plaid_Mock::save($state);
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET reconcile_after = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 1), $quiet->get_id()));
pbfp_rc()->refunds()->reconcile_due(10);
pbfp_assert_same(RefundState::SETTLED, pbfp_refund_rows($quiet)[0]['status'], 'Polling recovered the missed refund events.');
$row = pbfp_refund_rows($quiet)[0];
pbfp_assert('' !== (string) $row['reconcile_after'] && '' !== (string) $row['monitor_until'], 'A settled refund stays monitored for returns for a few days.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Refunds created outside WooCommerce are recorded and counted');
[$dashboard, $dashboard_transfer] = pbfp_paid('50.00');
$state = $mock::state();
$state['refunds']['dash-1'] = array('id' => 'dash-1', 'transfer_id' => $dashboard_transfer, 'amount' => '20.00', 'status' => 'pending', 'failure_reason' => null, 'ledger_id' => 'l', 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'network_trace_id' => null);
PayBridge_Test_Plaid_Mock::save($state);
$mock::add_refund_event('dash-1', 'refund.pending');
pbfp_rc()->event_sync()->run();
$rows = pbfp_refund_rows($dashboard);
pbfp_assert(1 === count($rows) && 'external' === $rows[0]['origin'] && 'dash-1' === $rows[0]['refund_id'], 'External refund recorded.');
pbfp_assert(pbfp_alert($dashboard, 'external_refund'), 'Merchant alerted to record it.');
pbfp_assert_same('30.00', pbfp_rc()->refunds()->eligibility(pbfp_reload($dashboard))->remaining, 'External refunds reduce the refundable amount.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Original debit returned after a refund: impossible to overlook');
delete_option('pbfp_test_mails');
[$double, $double_transfer] = pbfp_paid('60.00', array('posted', 'settled', 'funds_available'));
pbfp_assert(pbfp_wc_refund($double, '20.00') instanceof WC_Order_Refund, 'First refund.');
$first_refund = (string) pbfp_refund_rows($double)[0]['refund_id'];
$mock::add_refund_event($first_refund, 'refund.posted');
$mock::add_refund_event($first_refund, 'refund.settled');
pbfp_rc()->event_sync()->run();
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}paybridge_plaid_refunds SET created_at = %s WHERE order_id = %d", gmdate('Y-m-d H:i:s', time() - 300), $double->get_id()));
pbfp_assert(pbfp_wc_refund(pbfp_reload($double), '15.00') instanceof WC_Order_Refund, 'Second refund, still pending at Plaid.');
$second_refund = (string) pbfp_refund_rows($double)[1]['refund_id'];
$mock::add_event($double_transfer, 'returned', 'R10');
pbfp_rc()->event_sync()->run();
$double = pbfp_reload($double);
pbfp_assert_same(PaymentState::RETURNED, (string) $double->get_meta(OrderMeta::PAYMENT_STATE, true), 'The return is recorded.');
pbfp_assert(pbfp_alert($double, 'returned_after_refund'), 'Critical return-after-refund alert.');
pbfp_assert(! pbfp_alert($double, 'returned'), 'Not normalized into a plain return alert.');
pbfp_assert(1 === pbfp_note_count($double, 'CRITICAL'), 'High-severity private note.');
$critical = array_values(array_filter(pbfp_notes($double), static fn (string $note): bool => str_contains($note, 'CRITICAL')));
pbfp_assert(str_contains($critical[0], '$20.00') && ! str_contains($critical[0], '$35.00'), 'The note states the refunded amount that left the merchant (the cancelled refund is excluded).');
$mails = get_option('pbfp_test_mails');
pbfp_assert(is_array($mails) && 1 === count(array_filter($mails, static fn (array $mail): bool => str_contains((string) $mail['subject'], 'URGENT: ACH return after refund'))), 'Urgent merchant email.');
pbfp_assert_same('cancelled', $mock::state()['refunds'][$second_refund]['status'], 'The still-pending refund was cancelled at Plaid (the customer already has the money back).');
pbfp_assert_same(RefundState::CANCELLED, pbfp_refund_rows($double)[1]['status'], 'Cancellation recorded.');
pbfp_assert_same(RefundState::SETTLED, pbfp_refund_rows($double)[0]['status'], 'The settled refund is kept as history.');
pbfp_assert(null !== $double->get_date_paid() && $double_transfer === $double->get_transaction_id(), 'Original payment history preserved.');
$blocked = pbfp_wc_refund($double, '1.00');
pbfp_assert($blocked instanceof WP_Error && str_contains($blocked->get_error_message(), 'returned'), 'A returned payment cannot be refunded again.');

WP_CLI::success('PayBridge refund suite passed (HPOS=' . (getenv('PAYBRIDGE_PLAID_EXPECT_HPOS') ?: '?') . ').');
