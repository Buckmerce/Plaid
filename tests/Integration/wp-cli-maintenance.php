<?php

/**
 * Background maintenance follows real operational work (ADR-0021): the recurring
 * reconciliation runs while the gateway accepts payments or while payments, refunds, stored
 * events or a failed event sync of the configured Plaid account need it — never merely because
 * a payment once existed. Verified webhooks still trigger event sync when it is stopped.
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Admin\DiagnosticsPage;
use Buckmerce\Plaid\Admin\SiteHealth;
use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Refund\RefundState;
use Buckmerce\Plaid\Settings\Settings;

global $wpdb;
$mock = Buckmerce_Test_Plaid_Mock::class;
bmfp_configure();
bmfp_reset_world();
// Earlier suites left payments of this account in flight; they are not part of this scenario.
$wpdb->query("UPDATE {$wpdb->prefix}buckmerce_plaid_payment_locks SET reconcile_after = NULL, monitor_until = NULL, payment_state = IF(payment_state IN ('intent_created', 'intent_pending', 'transfer_created', 'pending', 'posted', 'settled'), 'failed', payment_state)");

function bmfp_mt(): Container
{
    return new Container();
}

function bmfp_mt_scheduled(): bool
{
    ( new Scheduler(bmfp_mt()) )->ensure_recurring();
    return as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP);
}

/** Plaid's return windows of the payment have closed. */
function bmfp_mt_close(WC_Order $order): void
{
    $order = bmfp_reload($order);
    $order->update_meta_data(OrderMeta::SETTLED_AT, gmdate('c', time() - 200 * DAY_IN_SECONDS));
    $order->update_meta_data(OrderMeta::STANDARD_RETURN_WINDOW, gmdate('Y-m-d', time() - 190 * DAY_IN_SECONDS));
    $order->update_meta_data(OrderMeta::UNAUTHORIZED_RETURN_WINDOW, gmdate('Y-m-d', time() - 100 * DAY_IN_SECONDS));
    $order->save();
    bmfp_mt()->monitor()->refresh(bmfp_reload($order));
}

/** @return array{WC_Order, string} */
function bmfp_mt_pay(string $total, array $lifecycle): array
{
    $order = bmfp_order($total);
    bmfp_assert('success' === bmfp_gateway()->process_payment($order->get_id())['result'], 'Checkout ' . $total);
    $transfer = Buckmerce_Test_Plaid_Mock::authorize(bmfp_mt()->attempts()->issue_link_token(bmfp_reload($order))->token);
    bmfp_mt()->completion()->complete(bmfp_reload($order));
    foreach ($lifecycle as $type) {
        Buckmerce_Test_Plaid_Mock::add_event($transfer, $type);
    }
    bmfp_mt()->event_sync()->run();
    return array(bmfp_reload($order), $transfer);
}

// ---------------------------------------------------------------------------------
WP_CLI::log('Gateway enabled and no payment yet: reconciliation is scheduled');
as_unschedule_all_actions(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP);
bmfp_assert(! Scheduler::has_work(Scheduler::pending_work(bmfp_scope())), 'Precondition: nothing to maintain.');
bmfp_assert(Scheduler::maintenance_active(Settings::load()), 'Accepting payments keeps maintenance active.');
bmfp_assert(bmfp_mt_scheduled(), 'Recurring reconciliation scheduled.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Gateway disabled with nothing left to maintain: reconciliation stops, even though payments once existed');
bmfp_configure(array('enabled' => 'no'));
bmfp_assert(null !== PaymentEpoch::get(bmfp_scope()), 'Payments existed with this account (epoch).');
bmfp_assert(! Scheduler::maintenance_active(Settings::load()), '"A payment once existed" is not maintenance work.');
bmfp_assert(! bmfp_mt_scheduled(), 'Recurring reconciliation unscheduled when truly idle.');
bmfp_assert_same('No', DiagnosticsPage::report()['Background maintenance active'], 'Diagnostics report idle maintenance.');
$health = ( new SiteHealth() )->test_background();
bmfp_assert('good' === $health['status'] && str_contains($health['label'], 'idle'), 'Site Health: idle, not an error.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Gateway disabled with an open payment: maintenance continues until its return windows close');
bmfp_configure();
[$open, $open_transfer] = bmfp_mt_pay('11.11', array('posted'));
bmfp_configure(array('enabled' => 'no'));
bmfp_assert_same(1, Scheduler::pending_work(bmfp_scope())['payments'], 'One monitored payment.');
bmfp_assert(bmfp_mt_scheduled(), 'Scheduled while a payment is in flight.');
$mock::add_event($open_transfer, 'settled');
$mock::add_event($open_transfer, 'funds_available');
( new Scheduler(bmfp_mt()) )->run_reconciliation();
bmfp_assert(bmfp_reload($open)->is_paid(), 'Reconciliation completed the payment while the gateway was disabled.');
bmfp_assert(bmfp_mt_scheduled(), 'Still scheduled inside the ACH return windows.');
bmfp_mt_close($open);
bmfp_assert(! bmfp_mt_scheduled(), 'Stops once the return windows closed.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Gateway disabled with an open refund: maintenance continues until the refund is final');
bmfp_configure();
[$refunded, $refunded_transfer] = bmfp_mt_pay('20.00', array('posted', 'settled', 'funds_available'));
bmfp_assert(bmfp_wc_refund(bmfp_reload($refunded), '4.00') instanceof WC_Order_Refund, 'Refund created.');
bmfp_configure(array('enabled' => 'no'));
bmfp_mt_close($refunded);
$work = Scheduler::pending_work(bmfp_scope());
bmfp_assert(0 === $work['payments'] && 1 === $work['refunds'], 'Only the refund needs maintenance.');
bmfp_assert(bmfp_mt_scheduled(), 'Scheduled while a refund is pending.');
$refund_row = bmfp_refund_rows($refunded)[0];
$mock::add_refund_event((string) $refund_row['refund_id'], 'refund.posted');
$mock::add_refund_event((string) $refund_row['refund_id'], 'refund.settled');
( new Scheduler(bmfp_mt()) )->run_reconciliation();
bmfp_assert_same(RefundState::SETTLED, (string) bmfp_refund_rows($refunded)[0]['status'], 'The refund settled while the gateway was disabled.');
bmfp_assert(bmfp_mt_scheduled(), 'A settled refund is still watched for returns.');
( new Buckmerce\Plaid\Persistence\RefundStore() )->schedule((int) $refund_row['id'], null, null);
bmfp_assert(! bmfp_mt_scheduled(), 'Stops once the refund is no longer monitored.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Gateway disabled with an event backlog or a failed sync: processing continues');
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$wpdb->prefix}buckmerce_plaid_events (environment, account_fp, event_id, event_type, transfer_id, event_data, status, attempts, lease_expires_at, created_at, updated_at)
     VALUES (%s, %s, %d, 'posted', %s, %s, 'retry', 1, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    'sandbox',
    bmfp_scope()->account_fp,
    999999,
    $open_transfer,
    (string) wp_json_encode(array('event_id' => '999999', 'event_type' => 'posted', 'transfer_id' => $open_transfer, 'timestamp' => gmdate('c'), 'transfer_amount' => '11.11')),
    gmdate('Y-m-d H:i:s', time() - 60)
));
bmfp_assert(Scheduler::pending_work(bmfp_scope())['events'], 'A stored event is waiting.');
bmfp_assert(bmfp_mt_scheduled(), 'Scheduled while events wait for processing.');
bmfp_mt()->event_sync()->run();
bmfp_assert(! Scheduler::pending_work(bmfp_scope())['events'], 'The backlog was processed while disabled.');
bmfp_assert(! bmfp_mt_scheduled(), 'Stops once the backlog is empty.');
// An event deferred for a retry (its backoff has not ended) cannot be claimed yet, but it is still
// work: without the recurring reconciliation nothing would ever claim it again.
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$wpdb->prefix}buckmerce_plaid_events (environment, account_fp, event_id, event_type, transfer_id, event_data, status, attempts, error_code, lease_expires_at, created_at, updated_at)
     VALUES (%s, %s, %d, 'posted', %s, %s, 'unmatched', 3, 'RATE_LIMIT_EXCEEDED', %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    'sandbox',
    bmfp_scope()->account_fp,
    999998,
    $open_transfer,
    (string) wp_json_encode(array('event_id' => '999998', 'event_type' => 'posted', 'transfer_id' => $open_transfer, 'timestamp' => gmdate('c'), 'transfer_amount' => '11.11')),
    gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS)
));
$event_store = new Buckmerce\Plaid\Persistence\TransferEventStore();
bmfp_assert(! $event_store->has_processable(bmfp_scope()) && $event_store->has_backlog(bmfp_scope()), 'A deferred event is not claimable yet, but it is backlog.');
bmfp_assert(Scheduler::pending_work(bmfp_scope())['events'], 'A deferred event is operational work.');
bmfp_assert(bmfp_mt_scheduled(), 'Scheduled while a deferred event waits for its retry.');
( new Scheduler(bmfp_mt()) )->run_reconciliation();
bmfp_assert($event_store->has_backlog(bmfp_scope()), 'The backoff is respected: the event is not processed early.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}buckmerce_plaid_events SET lease_expires_at = %s WHERE event_id = 999998", gmdate('Y-m-d H:i:s', time() - 60)));
( new Scheduler(bmfp_mt()) )->run_reconciliation();
bmfp_assert(! Scheduler::pending_work(bmfp_scope())['events'], 'The reconciliation processed the deferred event once its backoff ended.');
bmfp_assert(! bmfp_mt_scheduled(), 'Stops once no event is waiting or deferred.');
update_option(EventSyncService::HEALTH_OPTION_PREFIX . bmfp_scope()->key(), array('failures' => 2, 'last_error' => array('at' => gmdate('c'), 'code' => 'API_ERROR', 'category' => 'transient')));
bmfp_assert(bmfp_mt_scheduled(), 'A failed event sync keeps maintenance running until a sync succeeds.');
bmfp_mt()->event_sync()->run();
bmfp_assert_same(0, EventSyncService::failures(bmfp_scope()), 'A successful sync clears the failure count.');
bmfp_assert(! bmfp_mt_scheduled(), 'Idle again.');

// ---------------------------------------------------------------------------------
WP_CLI::log('A verified webhook while reconciliation is stopped still syncs and processes events');
bmfp_assert(! as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP), 'Precondition: stopped.');
$mock::add_event($open_transfer, 'returned', 'R01');
$body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'sandbox'));
bmfp_assert_same(200, bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body)['status'], 'Webhook accepted.');
bmfp_assert(1 <= bmfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK), 'Event sync queued by the webhook.');
bmfp_assert_same(PaymentState::RETURNED, (string) bmfp_reload($open)->get_meta(OrderMeta::PAYMENT_STATE, true), 'A late return is recorded without the recurring reconciliation.');
bmfp_assert('failed' === bmfp_reload($open)->get_status(), 'The order reflects the return.');

// ---------------------------------------------------------------------------------
WP_CLI::log('Without credentials nothing is scheduled');
bmfp_configure(array('enabled' => 'yes', 'secret' => ''));
bmfp_assert(! bmfp_mt_scheduled(), 'No credentials → nothing scheduled, even when enabled.');

bmfp_configure();
WP_CLI::success('Buckmerce maintenance suite passed (HPOS=' . getenv('BUCKMERCE_PLAID_EXPECT_HPOS') . ').');
