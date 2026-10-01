<?php
/**
 * Real Plaid Sandbox refund gate (scripts/test-sandbox-e2e.sh). Genuine /transfer/refund/*
 * calls through the real WooCommerce refund flow (wc_create_refund → process_refund).
 * Runs inside the disposable Sandbox site: wp eval-file tests/E2E/sandbox-refunds.php --use-include
 *
 * BMFP_STEP:
 *   create    partial refunds on the first $11.11 order: $1.11 (Plaid Sandbox: → returned),
 *             $2.22 (→ failed), $5.00 with the first Plaid response discarded (retried with the
 *             same idempotency key); an over-refund; a full refund of the second $11.11 order
 *   simulate  moves the $5.00 and the full refund to posted → settled (/sandbox/transfer/refund/simulate)
 *   verify    refund states, WooCommerce records, alerts, exact remaining amount, reconciliation,
 *             duplicate events and completion callbacks are harmless
 */

declare(strict_types=1);

use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Persistence\Installer;

global $wpdb;

$step = (string) getenv('BMFP_STEP');
$orders = json_decode((string) getenv('BMFP_ORDERS'), true);
$orders = is_array($orders) ? $orders : array();
$partial = wc_get_order((int) ($orders['11.11'] ?? 0));
$full = wc_get_order((int) ($orders['11.11-full'] ?? 0));
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('SANDBOX REFUND ASSERTION FAILED: ' . $message);
    }
    WP_CLI::log('  ok  ' . $message);
};
$check($partial instanceof WC_Order && $full instanceof WC_Order, 'The two $11.11 Sandbox orders exist.');
$rows = static function (WC_Order $order) use ($wpdb): array {
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY id', Installer::refunds_table(), $order->get_id()), ARRAY_A);
};
$by_amount = static function (WC_Order $order, string $amount) use ($rows): array {
    foreach ($rows($order) as $row) {
        if ($amount === $row['amount']) {
            return $row;
        }
    }
    throw new RuntimeException('No refund row of ' . $amount . ' for order ' . $order->get_id());
};
$refund = static fn (WC_Order $order, string $amount) => wc_create_refund(array('order_id' => $order->get_id(), 'amount' => $amount, 'reason' => 'Sandbox refund gate', 'refund_payment' => true));
$plaid_refunds = static function (WC_Order $order): array {
    return ( new Container() )->transfers()->get((string) $order->get_meta('_bmfp_transfer_id', true))->refunds;
};
$age = static function (WC_Order $order) use ($wpdb): void {
    // The duplicate-submit guard refuses identical amounts within a minute; these refunds differ, but keep them apart.
    $wpdb->query($wpdb->prepare('UPDATE %i SET created_at = %s WHERE order_id = %d', Installer::refunds_table(), gmdate('Y-m-d H:i:s', time() - 120), $order->get_id()));
};

switch ($step) {
    case 'create':
        $check('funds_available' === $partial->get_meta('_bmfp_payment_state', true) && 'funds_available' === $full->get_meta('_bmfp_payment_state', true), 'Both payments reached funds_available in Sandbox.');
        foreach (array('1.11', '2.22') as $amount) {
            $result = $refund($partial, $amount);
            $check($result instanceof WC_Order_Refund, 'Plaid accepted the partial refund of $' . $amount . ($result instanceof WP_Error ? ': ' . $result->get_error_message() : ''));
            $age($partial);
        }
        // The first genuine Plaid response to the next create is discarded, as if the connection dropped
        // after Plaid created the refund. Buckmerce must retry with the same idempotency key.
        $dropped = 0;
        $drop = static function ($response, array $args, string $url) use (&$dropped) {
            if (0 === $dropped && str_ends_with($url, '/transfer/refund/create')) {
                ++$dropped;
                return new WP_Error('http_request_failed', 'Sandbox gate: response lost after the request reached Plaid');
            }
            return $response;
        };
        add_filter('http_response', $drop, 10, 3);
        $result = $refund(wc_get_order($partial->get_id()), '5.00');
        remove_filter('http_response', $drop, 10);
        $check(1 === $dropped, 'A genuine Plaid response was dropped once.');
        $check($result instanceof WC_Order_Refund, 'The lost response was recovered with the same idempotency key' . ($result instanceof WP_Error ? ': ' . $result->get_error_message() : ''));
        $five = array_values(array_filter($plaid_refunds($partial), static fn ($r): bool => '5.00' === $r->amount));
        $check(1 === count($five), 'Plaid holds exactly one $5.00 refund (Plaid idempotency, not a duplicate).');
        $check($five[0]->id === $by_amount($partial, '5.00')['refund_id'], 'The recorded refund is the one Plaid created.');
        $age($partial);
        $calls = count($plaid_refunds($partial));
        $local_rows = count($rows($partial));
        $eligibility = ( new Container() )->refunds()->eligibility(wc_get_order($partial->get_id()));
        $check($eligibility->allowed && '2.78' === $eligibility->remaining, 'Buckmerce computes exactly $2.78 refundable while the three refunds are in flight.');
        // WooCommerce's own limit refuses first here; Buckmerce's stricter limit (refunds WooCommerce does
        // not know about) is exercised in tests/Integration/wp-cli-refunds.php.
        $over = $refund(wc_get_order($partial->get_id()), '3.00');
        $check($over instanceof WP_Error, 'Over-refund of $3.00 refused' . ($over instanceof WP_Error ? ' (' . $over->get_error_message() . ')' : ''));
        $check($calls === count($plaid_refunds($partial)) && $local_rows === count($rows($partial)), 'No Plaid refund and no refund record for the refused over-refund.');
        $result = $refund($full, '11.11');
        $check($result instanceof WC_Order_Refund, 'Full refund accepted by Plaid' . ($result instanceof WP_Error ? ': ' . $result->get_error_message() : ''));
        $check('refunded' === wc_get_order($full->get_id())->get_status(), 'WooCommerce marks the fully refunded order Refunded.');
        foreach (array($partial, $full) as $order) {
            foreach ($rows($order) as $row) {
                $check('' !== $row['refund_id'] && in_array($row['status'], array('pending', 'posted', 'settled', 'failed', 'returned'), true), 'Order #' . $order->get_id() . ' refund $' . $row['amount'] . ' recorded with Plaid ID (' . $row['status'] . ').');
            }
        }
        break;

    case 'simulate':
        $client = ( new Container() )->client();
        foreach (array($by_amount($partial, '5.00'), $by_amount($full, '11.11')) as $row) {
            foreach (array('refund.posted', 'refund.settled') as $event) {
                $client->post('/sandbox/transfer/refund/simulate', array('refund_id' => $row['refund_id'], 'event_type' => $event));
            }
            $check(true, 'Simulated posted → settled for refund $' . $row['amount']);
        }
        break;

    case 'verify':
        $partial = wc_get_order($partial->get_id());
        $expect = array('1.11' => 'returned', '2.22' => 'failed', '5.00' => 'settled');
        foreach ($expect as $amount => $status) {
            $check($status === $by_amount($partial, $amount)['status'], 'Refund $' . $amount . ' is ' . $status . ' (genuine Plaid refund events).');
        }
        $check('settled' === $by_amount($full, '11.11')['status'], 'Full refund settled.');
        $alerts = (array) get_option('buckmerce_plaid_payment_alerts', array());
        $types = array_map(static fn ($alert): string => (string) ($alert['type'] ?? ''), array_filter($alerts, static fn ($alert): bool => (int) ($alert['order_id'] ?? 0) === $partial->get_id()));
        $check(in_array('refund_returned', $types, true) && in_array('refund_failed', $types, true), 'Failed and returned refunds raised merchant alerts.');
        $eligibility = ( new Container() )->refunds()->eligibility($partial);
        $check($eligibility->allowed && '6.11' === $eligibility->remaining, 'Remaining refundable amount is exact ($6.11: failed/returned refunds do not count).');
        $check('funds_available' === $partial->get_meta('_bmfp_payment_state', true), 'Refund events never changed the payment state.');
        // Reconciliation (/transfer/refund/get) agrees with the event-driven state.
        $wpdb->query($wpdb->prepare('UPDATE %i SET reconcile_after = %s WHERE order_id IN (%d, %d)', Installer::refunds_table(), gmdate('Y-m-d H:i:s', time() - 1), $partial->get_id(), $full->get_id()));
        $checked = ( new Container() )->refunds()->reconcile_due(20);
        $check($checked >= 2, 'Reconciliation re-read the refunds from Plaid.');
        foreach ($expect as $amount => $status) {
            $check($status === $by_amount($partial, $amount)['status'], 'After reconciliation refund $' . $amount . ' is still ' . $status . '.');
        }
        // Duplicate events and completion callbacks are harmless.
        $notes = count(wc_get_order_notes(array('order_id' => $partial->get_id(), 'limit' => 500)));
        $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'received', attempts = 0 WHERE order_id = %d", Installer::events_table(), $partial->get_id()));
        ( new Container() )->event_sync()->run();
        ( new Container() )->completion()->complete(wc_get_order($partial->get_id()));
        ( new Container() )->completion()->complete(wc_get_order($partial->get_id()));
        $check($notes === count(wc_get_order_notes(array('order_id' => $partial->get_id(), 'limit' => 500))), 'Replayed genuine events and repeated completion callbacks changed nothing.');
        WP_CLI::success(sprintf('Real Plaid Sandbox refund gate passed: order #%d partial refunds $1.11 returned, $2.22 failed, $5.00 settled (lost response recovered, no duplicate); order #%d full refund $11.11 settled.', $partial->get_id(), $full->get_id()));
        break;

    default:
        throw new RuntimeException('Unknown BMFP_STEP ' . $step);
}
