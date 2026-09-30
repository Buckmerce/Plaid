<?php

declare(strict_types=1);

namespace PayBridge\Plaid\CLI;

use PayBridge\Plaid\Admin\ConnectionTester;
use PayBridge\Plaid\Admin\DiagnosticsPage;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\Settings\Settings;

/**
 * Operational commands for PayBridge for Plaid. Output never contains secrets.
 */
final class Command
{
    /**
     * Prints the sanitized diagnostics report (safe to share with support).
     *
     * ## EXAMPLES
     *
     *     wp paybridge-plaid status
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function status(array $args, array $assoc_args): void
    {
        foreach (DiagnosticsPage::report() as $label => $value) {
            \WP_CLI::line($label . ': ' . $value);
        }
    }

    /**
     * Runs the read-only Plaid connectivity check for the configured environment.
     *
     * @subcommand test-connection
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function test_connection(array $args, array $assoc_args): void
    {
        $result = ( new ConnectionTester() )->test(Settings::load());
        update_option(ConnectionTester::LAST_RESULT_OPTION, $result + array('at' => gmdate('c')), false);
        'connected' === $result['status'] ? \WP_CLI::success(ConnectionTester::message($result)) : \WP_CLI::error(ConnectionTester::message($result));
    }

    /**
     * Pulls new Plaid transfer events (/transfer/event/sync) and applies them.
     *
     * @subcommand sync-events
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function sync_events(array $args, array $assoc_args): void
    {
        $result = ( new Container() )->event_sync()->run();
        \WP_CLI::line((string) wp_json_encode($result));
        'failed' === $result['status'] ? \WP_CLI::error('Event sync failed; see the paybridge-for-plaid logs.') : \WP_CLI::success('Event sync finished.');
    }

    /**
     * Runs one bounded reconciliation pass (event catch-up plus stale orders).
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function reconcile(array $args, array $assoc_args): void
    {
        $result = ( new Container() )->reconciliation()->run();
        \WP_CLI::line((string) wp_json_encode($result));
        'failed' === $result['status'] ? \WP_CLI::error('Reconciliation failed; see the paybridge-for-plaid logs.') : \WP_CLI::success('Reconciliation finished.');
    }

    /**
     * Re-reads Plaid for one order (same as "Sync with Plaid" on the order screen).
     *
     * ## OPTIONS
     *
     * <order-id>
     * : WooCommerce order ID.
     *
     * @subcommand sync-order
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function sync_order(array $args, array $assoc_args): void
    {
        $order = wc_get_order(absint($args[0] ?? 0));
        if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            \WP_CLI::error('Not a PayBridge order.');
        }
        $container = new Container();
        $container->synchronizer()->sync($order);
        $container->refunds()->sync_order($order);
        $order = wc_get_order($order->get_id());
        $container->monitor()->refresh($order);
        \WP_CLI::success(sprintf('Order %d: payment state %s, order status %s.', $order->get_id(), (string) $order->get_meta('_pbfp_payment_state', true), $order->get_status()));
    }

    /**
     * Lists the Plaid refunds PayBridge recorded for an order.
     *
     * ## OPTIONS
     *
     * <order-id>
     * : WooCommerce order ID.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function refunds(array $args, array $assoc_args): void
    {
        $order = wc_get_order(absint($args[0] ?? 0));
        if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            \WP_CLI::error('Not a PayBridge order.');
        }
        $refunds = ( new Container() )->refunds();
        foreach ($refunds->for_order($order) as $record) {
            \WP_CLI::line(sprintf('#%d  $%s  %s  refund=%s  wc_refund=%d  origin=%s  code=%s  updated=%s', $record->id, $record->amount, $record->status, '' === $record->refund_id ? '-' : $record->refund_id, $record->wc_refund_id, $record->origin, '' === $record->failure_code ? '-' : $record->failure_code, $record->updated_at));
        }
        $eligibility = $refunds->eligibility($order);
        \WP_CLI::line(sprintf('Refunded: $%s; refundable now: %s', $eligibility->refunded, $eligibility->allowed ? '$' . $eligibility->remaining : 'no (' . $eligibility->code . ')'));
    }

    /**
     * Sandbox only: asks Plaid to move a refund to its next state (refund.posted, refund.settled,
     * refund.failed or refund.returned). Production refuses Sandbox endpoints.
     *
     * ## OPTIONS
     *
     * <refund-id>
     * : Plaid refund ID.
     *
     * <event-type>
     * : refund.posted | refund.settled | refund.failed | refund.returned
     *
     * @subcommand simulate-refund
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function simulate_refund(array $args, array $assoc_args): void
    {
        $settings = Settings::load();
        if ('sandbox' !== $settings->environment_name()) {
            \WP_CLI::error('Refund simulation is only available in the Sandbox environment.');
        }
        $refund_id = (string) ($args[0] ?? '');
        $event = (string) ($args[1] ?? '');
        if (! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $refund_id) || ! in_array($event, array('refund.posted', 'refund.settled', 'refund.failed', 'refund.returned'), true)) {
            \WP_CLI::error('Usage: wp paybridge-plaid simulate-refund <refund-id> <refund.posted|refund.settled|refund.failed|refund.returned>');
        }
        $body = array('refund_id' => $refund_id, 'event_type' => $event);
        if ('refund.returned' === $event) {
            $body['failure_reason'] = array('failure_code' => 'R01', 'description' => 'Sandbox simulated return');
        }
        $response = ( new Container($settings) )->client()->post('/sandbox/transfer/refund/simulate', $body);
        \WP_CLI::success('Plaid accepted the simulation (request_id ' . $response->request_id . ').');
    }

    /**
     * Asks Plaid Sandbox to send a real, signed TRANSFER_EVENTS_UPDATE webhook.
     *
     * Sandbox only. The URL must be publicly reachable over HTTPS by Plaid.
     *
     * ## OPTIONS
     *
     * [--url=<url>]
     * : Webhook URL. Defaults to this site's PayBridge webhook endpoint.
     *
     * @subcommand fire-sandbox-webhook
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function fire_sandbox_webhook(array $args, array $assoc_args): void
    {
        $settings = Settings::load();
        if ('sandbox' !== $settings->environment_name()) {
            \WP_CLI::error('Sandbox webhooks can only be fired in the Sandbox environment.');
        }
        $url = (string) ($assoc_args['url'] ?? rest_url(RestRoutes::NAMESPACE . '/webhook'));
        if (! wp_http_validate_url($url) || 'https' !== wp_parse_url($url, PHP_URL_SCHEME)) {
            \WP_CLI::error('The webhook URL must be a public HTTPS URL.');
        }
        $response = ( new Container($settings) )->client()->post('/sandbox/transfer/fire_webhook', array('webhook' => $url));
        \WP_CLI::success('Plaid accepted the request (request_id ' . $response->request_id . ').');
    }
}
