<?php
/**
 * Real Plaid Sandbox webhook gate, driven by scripts/test-sandbox-e2e.sh in public-URL (ngrok) mode.
 * Runs inside the disposable Sandbox site: wp eval-file tests/E2E/sandbox-webhooks.php --use-include
 *
 * PBFP_STEP selects the step:
 *   catch-up      pull the Plaid account's historical events so the cursor is at the head
 *   pre-webhook   the new orders' events have NOT been synced; clear queued syncs
 *   rearm         prepare for another genuine notification
 *   await-webhook a genuine Plaid-signed webhook arrived through the tunnel, verified, sync queued
 *   lifecycle     the webhook-driven event sync produced the expected order lifecycles
 *   attacks       forged/tampered/replayed requests against the public URL never mutate anything
 *   after-replay  a genuine replay was harmless
 *   stale         the genuine token is rejected once older than five minutes
 */

declare(strict_types=1);

use PayBridge\Plaid\Background\EventSyncService;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\REST\WebhookController;

global $wpdb;

$step = (string) getenv('PBFP_STEP');
$orders = json_decode((string) getenv('PBFP_ORDERS'), true);
$orders = is_array($orders) ? $orders : array();
$public_url = rtrim((string) getenv('PBFP_PUBLIC_URL'), '/');
$webhook_url = $public_url . '/wp-json/paybridge-for-plaid/v1/webhook';
$events_table = Installer::events_table();

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('SANDBOX WEBHOOK ASSERTION FAILED: ' . $message);
    }
    WP_CLI::log('  ok  ' . $message);
};
/** Reads an option straight from the database (other processes wrote it). */
$fresh = static function (string $name) use ($wpdb) {
    $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));
    return null === $value ? false : maybe_unserialize($value);
};
$state = static function (?array $update = null) use ($fresh): array {
    $current = $fresh('pbfp_test_gate_state');
    $current = is_array($current) ? $current : array();
    if (null !== $update) {
        $current = array_merge($current, $update);
        update_option('pbfp_test_gate_state', $current, false);
    }
    return $current;
};
$pending_syncs = static function (): int {
    return count(as_get_scheduled_actions(array('hook' => Scheduler::EVENT_SYNC_HOOK, 'group' => Scheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10), 'ids'));
};
$order_ids = array_map('intval', array_values($orders));
$transfer_ids = static function () use ($orders): array {
    $ids = array();
    foreach ($orders as $amount => $order_id) {
        $order = wc_get_order((int) $order_id);
        $ids[$amount] = $order instanceof WC_Order ? (string) $order->get_meta('_pbfp_transfer_id', true) : '';
    }
    return $ids;
};
/** Everything an attacker could try to change (events of other Plaid clients are excluded). */
$fingerprint = static function () use ($order_ids, $wpdb, $events_table): array {
    $print = array();
    foreach ($order_ids as $order_id) {
        $print['events_' . $order_id] = $wpdb->get_results($wpdb->prepare('SELECT event_id, status, attempts FROM %i WHERE order_id = %d ORDER BY event_id', $events_table, $order_id), ARRAY_A);
    }
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        $print[$order_id] = array(
            $order->get_status(),
            $order->is_paid(),
            (string) $order->get_meta('_pbfp_payment_state', true),
            (string) $order->get_meta('_pbfp_transfer_status', true),
            (string) $order->get_meta('_pbfp_last_event_id', true),
            (string) $order->get_meta('_pbfp_return_code', true),
            (string) $order->get_meta('_pbfp_return_alerted', true),
            count(wc_get_order_notes(array('order_id' => $order_id))),
        );
    }
    return $print;
};
$post = static function (string $body, array $headers = array(), string $method = 'POST') use ($webhook_url): int {
    $response = wp_remote_request($webhook_url, array('method' => $method, 'headers' => $headers + array('Content-Type' => 'application/json'), 'body' => 'GET' === $method ? null : $body, 'timeout' => 45));
    if (is_wp_error($response)) {
        throw new RuntimeException('Request through the tunnel failed: ' . $response->get_error_message());
    }
    return (int) wp_remote_retrieve_response_code($response);
};
$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$b64_decode = static fn (string $value): string => (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

switch ($step) {
    case 'catch-up':
        $runs = 0;
        do {
            $result = ( new Container() )->event_sync()->run();
            ++$runs;
            WP_CLI::log(sprintf('  event sync run %d: %s', $runs, (string) wp_json_encode($result)));
            $check('failed' !== $result['status'], 'Historical event sync run ' . $runs . ' succeeded');
        } while ($result['more'] && 'ok' === $result['status'] && $runs < 40);
        foreach ($wpdb->get_results($wpdb->prepare('SELECT status, error_code, COUNT(*) AS n FROM %i GROUP BY status, error_code', $events_table), ARRAY_A) as $row) {
            WP_CLI::log(sprintf('  historical events: %-10s %-22s %d', $row['status'], (string) $row['error_code'], $row['n']));
        }
        $check(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', $events_table, 'processing', 'abandoned')), 'No historical event is stuck or abandoned');
        $check(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $events_table, 'processed')), 'No historical transfer of another store touched this store');
        break;

    case 'pre-webhook':
        $ids = $transfer_ids();
        $check(count($orders) === count(array_filter($ids)) && count($orders) >= 4, 'Every Sandbox order is bound to its Plaid transfer after Transfer UI');
        $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- Test-only dynamic IN list.
        $synced = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE transfer_id IN ({$placeholders})", $events_table, ...array_values($ids)));
        $check(0 === $synced, 'None of the new transfers\' events has been synced yet (the webhook must drive it)');
        as_unschedule_all_actions(Scheduler::EVENT_SYNC_HOOK, array(), Scheduler::GROUP);
        $check(0 === $pending_syncs(), 'No event sync is queued before the webhook');
        $captures = $fresh('pbfp_test_webhook_captures');
        $state(array('captures_before' => is_array($captures) ? count($captures) : 0, 'fired_at' => time(), 'cursor_before' => (string) $fresh('paybridge_plaid_event_cursor_sandbox')));
        break;

    case 'rearm':
        as_unschedule_all_actions(Scheduler::EVENT_SYNC_HOOK, array(), Scheduler::GROUP);
        $captures = $fresh('pbfp_test_webhook_captures');
        $state(array('captures_before' => is_array($captures) ? count($captures) : 0, 'fired_at' => time()));
        $check(0 === $pending_syncs(), 'Ready for another genuine notification');
        break;

    case 'await-webhook':
        $before = (int) ($state()['captures_before'] ?? 0);
        $genuine = null;
        $deadline = time() + 150;
        while (null === $genuine && time() < $deadline) {
            $captures = $fresh('pbfp_test_webhook_captures');
            foreach (array_slice(is_array($captures) ? $captures : array(), $before) as $capture) {
                if (200 === $capture['status'] && '' !== $capture['jwt'] && ! str_starts_with($capture['user_agent'], 'WordPress/')) {
                    $genuine = $capture;
                    break;
                }
            }
            if (null === $genuine) {
                sleep(2);
            }
        }
        $check(null !== $genuine, 'A genuine Plaid webhook reached the public URL through the tunnel and passed verification');
        $body = json_decode(base64_decode($genuine['body']), true);
        $check(is_array($body) && 'TRANSFER' === $body['webhook_type'] && 'TRANSFER_EVENTS_UPDATE' === $body['webhook_code'] && 'sandbox' === $body['environment'], 'It is a Sandbox TRANSFER_EVENTS_UPDATE (' . base64_decode($genuine['body']) . ')');
        [$header] = explode('.', $genuine['jwt']);
        $jwt_header = json_decode($b64_decode($header), true);
        $check('ES256' === ($jwt_header['alg'] ?? null) && is_string($jwt_header['kid'] ?? null), 'Plaid signed it with ES256 and a key ID (user agent: ' . $genuine['user_agent'] . ')');
        $last = $fresh(WebhookController::LAST_WEBHOOK_OPTION);
        $check(is_array($last) && 'event_sync_queued' === $last['outcome'] && 'TRANSFER_EVENTS_UPDATE' === $last['code'], 'Diagnostics record the verified webhook');
        $check(1 === $pending_syncs(), 'The verified webhook queued exactly one event sync');
        $state(array('genuine' => $genuine, 'last_webhook_at' => $last['at']));
        break;

    case 'lifecycle':
        $expect = array(
            '11.11' => array('state' => 'funds_available', 'paid' => true, 'event' => 'funds_available'),
            '22.22' => array('state' => 'failed', 'paid' => false, 'event' => 'failed'),
            '33.33' => array('state' => 'returned', 'paid' => false, 'event' => 'returned', 'return' => 'R01'),
            '11.11-full' => array('state' => 'funds_available', 'paid' => true, 'event' => 'funds_available'),
        );
        $ids = $transfer_ids();
        foreach ($expect as $amount => $want) {
            $order = wc_get_order((int) $orders[$amount]);
            $rows = $wpdb->get_results($wpdb->prepare('SELECT event_type, status, order_id FROM %i WHERE transfer_id = %s ORDER BY event_id', $events_table, $ids[$amount]), ARRAY_A);
            $types = array_column($rows, 'event_type');
            $state_now = (string) $order->get_meta('_pbfp_payment_state', true);
            WP_CLI::log(sprintf('  $%s order #%d state=%s wc=%s paid=%s events=%s', $amount, $order->get_id(), $state_now, $order->get_status(), $order->is_paid() ? 'yes' : 'no', implode('>', $types)));
            $check(in_array($want['event'], $types, true), '$' . $amount . ': the webhook-driven sync stored the "' . $want['event'] . '" event');
            $check(array() === array_filter($rows, static fn (array $row): bool => 'processed' !== $row['status'] || (int) $row['order_id'] !== $order->get_id()), '$' . $amount . ': every event of the transfer was processed for order #' . $order->get_id());
            $check($want['state'] === $state_now && $want['paid'] === $order->is_paid(), '$' . $amount . ': order is ' . $want['state'] . ($want['paid'] ? ' and paid' : ' and not paid'));
            if (isset($want['return'])) {
                $check($want['return'] === $order->get_meta('_pbfp_return_code', true) && 'yes' === $order->get_meta('_pbfp_return_alerted', true), '$' . $amount . ': ACH return ' . $want['return'] . ' recorded and alerted');
            }
        }
        $check(strtotime((string) $fresh(EventSyncService::LAST_SYNC_OPTION)) >= (int) ($state()['fired_at'] ?? PHP_INT_MAX), 'Event sync ran after the webhook');
        $check((string) $fresh('paybridge_plaid_event_cursor_sandbox') !== (string) ($state()['cursor_before'] ?? ''), 'The event cursor advanced');
        break;

    case 'attacks':
        $genuine = $state()['genuine'] ?? null;
        $check(is_array($genuine), 'A genuine webhook capture is available');
        $jwt = $genuine['jwt'];
        $body = base64_decode($genuine['body']);
        [$header, $payload, $signature] = explode('.', $jwt);
        $kid = (string) json_decode($b64_decode($header), true)['kid'];
        $claims = json_decode($b64_decode($payload), true);
        $check(time() - (int) $claims['iat'] < 240, 'The genuine token is fresh enough for the replay checks');
        as_unschedule_all_actions(Scheduler::EVENT_SYNC_HOOK, array(), Scheduler::GROUP);
        $before = $fingerprint();
        $last_before = $fresh(WebhookController::LAST_WEBHOOK_OPTION);

        // Attacker-controlled P-256 key: a correctly shaped ES256 token that Plaid never signed.
        $attacker = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        openssl_pkey_export($attacker, $attacker_pem);
        $forge = static function (string $forged_body, string $forged_kid) use ($attacker_pem): string {
            return \PayBridge\Plaid\Vendor\Firebase\JWT\JWT::encode(array('iat' => time(), 'request_body_sha256' => hash('sha256', $forged_body)), $attacker_pem, 'ES256', $forged_kid);
        };
        $production_body = (string) wp_json_encode(array_merge((array) json_decode($body, true), array('environment' => 'production')));
        $flipped = $signature;
        $middle = intdiv(strlen($flipped), 2);
        $flipped[$middle] = 'A' === $flipped[$middle] ? 'B' : 'A';
        $cases = array(
            'missing Plaid-Verification header' => array(401, $body, array()),
            'malformed JWT' => array(401, $body, array('Plaid-Verification' => 'not-a-jwt')),
            'genuine JWT, body with one extra byte' => array(401, $body . ' ', array('Plaid-Verification' => $jwt)),
            'genuine JWT, body switched to production' => array(401, $production_body, array('Plaid-Verification' => $jwt)),
            'genuine JWT with a flipped signature byte' => array(401, $body, array('Plaid-Verification' => $header . '.' . $payload . '.' . $flipped)),
            'alg none with Plaid key ID' => array(401, $body, array('Plaid-Verification' => $b64((string) wp_json_encode(array('alg' => 'none', 'kid' => $kid))) . '.' . $payload . '.')),
            'HS256 algorithm confusion with Plaid key ID' => array(401, $body, array('Plaid-Verification' => (static function () use ($b64, $kid, $payload): string {
                $head = $b64((string) wp_json_encode(array('alg' => 'HS256', 'kid' => $kid, 'typ' => 'JWT')));
                return $head . '.' . $payload . '.' . $b64(hash_hmac('sha256', $head . '.' . $payload, $kid, true));
            })())),
            'attacker ES256 signature with Plaid key ID' => array(401, $body, array('Plaid-Verification' => $forge($body, $kid))),
            'attacker ES256 signature with unknown key ID' => array(401, $body, array('Plaid-Verification' => $forge($body, wp_generate_uuid4()))),
            'oversized body' => array(413, str_repeat(' ', 70000) . $body, array('Plaid-Verification' => $jwt)),
        );
        foreach ($cases as $name => [$expected, $raw, $headers]) {
            $check($expected === $post($raw, $headers), $name . ' → HTTP ' . $expected);
            $check(0 === $pending_syncs(), $name . ' → nothing queued');
        }
        $check(404 === $post('', array(), 'GET'), 'GET on the webhook route → HTTP 404');
        $check($before === $fingerprint(), 'No rejected request changed any order, note or event');
        $check($last_before === $fresh(WebhookController::LAST_WEBHOOK_OPTION), 'Rejected requests are never recorded as verified webhooks');
        $rejection = $fresh(WebhookController::LAST_REJECTION_OPTION);
        $check(is_array($rejection) && '' !== $rejection['reason'], 'Diagnostics record a rejection reason (' . ( is_array($rejection) ? $rejection['reason'] : '-' ) . ')');

        // A genuine replay inside the five-minute window is accepted and must be harmless.
        $check(200 === $post($body, array('Plaid-Verification' => $jwt)), 'Identical replay of the genuine webhook → HTTP 200');
        $check(1 === $pending_syncs(), 'The replay queued one (coalesced) event sync');
        $state(array('fingerprint' => $before));
        break;

    case 'after-replay':
        $check(0 === $pending_syncs(), 'The replayed notification was processed');
        $check(($state()['fingerprint'] ?? null) === $fingerprint(), 'Replaying a genuine webhook changed nothing: no new events, notes, emails or state changes');
        break;

    case 'stale':
        $genuine = $state()['genuine'] ?? null;
        $check(is_array($genuine), 'A genuine webhook capture is available');
        [, $payload] = explode('.', $genuine['jwt']);
        $iat = (int) json_decode($b64_decode($payload), true)['iat'];
        $wait = $iat + 310 - time();
        if ($wait > 0) {
            WP_CLI::log(sprintf('  waiting %d s until the genuine token is older than five minutes…', $wait));
            sleep($wait);
        }
        as_unschedule_all_actions(Scheduler::EVENT_SYNC_HOOK, array(), Scheduler::GROUP);
        $before = $fingerprint();
        $check(401 === $post(base64_decode($genuine['body']), array('Plaid-Verification' => $genuine['jwt'])), 'Replay of the genuine webhook after five minutes → HTTP 401 (stale)');
        $check(0 === $pending_syncs() && $before === $fingerprint(), 'The stale replay changed nothing');
        break;

    default:
        throw new RuntimeException('Unknown PBFP_STEP: ' . $step);
}
WP_CLI::success('Sandbox webhook step "' . $step . '" passed.');
