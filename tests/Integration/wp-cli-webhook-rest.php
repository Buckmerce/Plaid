<?php

/** Webhook authorization and security matrix through the real REST stack. */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Payment\OrderMeta;

bmfp_configure();
bmfp_reset_world();
$mock = Buckmerce_Test_Plaid_Mock::class;

$route = rest_get_server()->get_routes()['/buckmerce-plaid/v1/webhook'][0] ?? null;
bmfp_assert(is_array($route) && is_callable($route['permission_callback'] ?? null), 'Webhook has a real permission callback.');
bmfp_assert(is_callable($route['callback'] ?? null), 'Webhook has a route callback.');
bmfp_assert($route['permission_callback'][0] === $route['callback'][0], 'Authorization and processing share the request-scoped controller.');
$controller = $route['permission_callback'][0];
$permission = $route['permission_callback'];
$handler = $route['callback'];
$probe = (string) wp_json_encode(array('webhook_type' => 'ITEM', 'webhook_code' => 'ERROR', 'environment' => 'sandbox'));
$request = new WP_REST_Request('POST', '/buckmerce-plaid/v1/webhook');
$request->set_body($probe);
$request->set_header('Plaid-Verification', $mock::sign($probe));
wp_set_current_user(0);
delete_transient('bmfp_jwk_sandbox_' . substr(hash('sha256', $mock::KID), 0, 32));
$key_calls = count($mock::calls('/webhook_verification_key/get'));
bmfp_assert_same(true, $permission($request), 'Signed webhook is authorized without a WordPress user or nonce.');
bmfp_assert_same($key_calls + 1, count($mock::calls('/webhook_verification_key/get')), 'Authorization fetches the verification key once.');
$request->set_body((string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'sandbox')));
$request->set_param('verified_payload', array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'sandbox'));
$handled = $handler($request);
bmfp_assert_same(200, $handled->get_status(), 'The same request can be handled after authorization.');
bmfp_assert_same($key_calls + 1, count($mock::calls('/webhook_verification_key/get')), 'Processing does not verify or fetch the key again.');
bmfp_assert_same(0, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Only the verified ITEM payload is processed, not mutated body or caller parameter.');
bmfp_assert_same(401, $handler($request)->get_error_data()['status'], 'A verified payload cannot be replayed through the handler.');
$forged = new WP_REST_Request('POST', '/buckmerce-plaid/v1/webhook');
$forged->set_param('verified_payload', array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE'));
bmfp_assert_same(401, $handler($forged)->get_error_data()['status'], 'Client parameters cannot bypass authorization.');
$bad = new WP_REST_Request('POST', '/buckmerce-plaid/v1/webhook');
$bad->set_body($probe);
$bad->set_header('Plaid-Verification', 'not.a.jwt');
bmfp_assert($permission($bad) instanceof WP_Error, 'An invalid signature is rejected in the permission callback.');
bmfp_assert_same(401, $handler($bad)->get_error_data()['status'], 'Rejected requests cannot reach business processing.');
bmfp_configure(array('secret' => ''));
$unconfigured = new WP_REST_Request('POST', '/buckmerce-plaid/v1/webhook');
$unconfigured->set_body($probe);
$unconfigured->set_header('Plaid-Verification', $mock::sign($probe));
bmfp_assert_same(503, $permission($unconfigured)->get_error_data()['status'], 'Unconfigured Plaid credentials are retryable.');
bmfp_configure();

// A paid order that must never change because of an invalid webhook.
$order = bmfp_order('11.11');
bmfp_gateway()->process_payment($order->get_id());
$token = ( new Buckmerce\Plaid\Container() )->attempts()->issue_link_token(bmfp_reload($order));
$transfer = $mock::authorize($token->token);
( new Buckmerce\Plaid\Container() )->completion()->complete(bmfp_reload($order));
$mock::advance($transfer);
$before = array(bmfp_reload($order)->get_status(), bmfp_meta_state($order), (int) bmfp_reload($order)->get_meta(OrderMeta::LAST_EVENT_ID, true));

function bmfp_meta_state(WC_Order $order): string
{
    return (string) bmfp_reload($order)->get_meta(OrderMeta::PAYMENT_STATE, true);
}

$body = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'sandbox'));
$b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
$signed_parts = explode('.', $mock::sign($body));
$cases = array(
    'missing header' => array(401, $body, array()),
    'malformed JWT' => array(401, $body, array('plaid-verification' => 'not.a.jwt')),
    'invalid signature' => array(401, $body, array('plaid-verification' => $signed_parts[0] . '.' . $signed_parts[1] . '.' . $b64('invalid'))),
    'modified body' => array(401, str_replace('sandbox', 'production', $body), array('plaid-verification' => $mock::sign($body))),
    'wrong alg' => array(401, $body, array('plaid-verification' => $b64('{"alg":"HS256","kid":"' . $mock::KID . '","typ":"JWT"}') . '.' . $b64('{"iat":' . time() . ',"request_body_sha256":"' . hash('sha256', $body) . '"}') . '.' . $b64('sig'))),
    'alg none' => array(401, $body, array('plaid-verification' => $b64('{"alg":"none","kid":"' . $mock::KID . '"}') . '.' . $b64('{"iat":' . time() . '}') . '.')),
    'unknown kid' => array(401, $body, array('plaid-verification' => $mock::sign($body, array(), 'unknown-kid'))),
    'stale token' => array(401, $body, array('plaid-verification' => $mock::sign($body, array('iat' => time() - 3600)))),
    'oversized body' => array(413, str_repeat(' ', 70000) . $body, array('plaid-verification' => $mock::sign(str_repeat(' ', 70000) . $body))),
);
delete_option('buckmerce_plaid_last_webhook');
delete_option('buckmerce_plaid_last_webhook_rejection');
foreach ($cases as $name => [$status, $raw, $headers]) {
    $response = bmfp_rest('/webhook', array(), $headers, $raw);
    bmfp_assert_same($status, $response['status'], 'Webhook case: ' . $name);
    bmfp_assert_same(0, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Invalid webhook must not enqueue work: ' . $name);
    bmfp_assert(! str_contains((string) wp_json_encode($response['data']), 'request_body_sha256'), 'Error responses leak no verification details: ' . $name);
}

$rejection = get_option('buckmerce_plaid_last_webhook_rejection');
bmfp_assert(is_array($rejection) && 'missing_header' === $rejection['reason'] && 401 === $rejection['status'], 'Diagnostics record the first rejection (throttled): ' . wp_json_encode($rejection));
bmfp_assert(false === get_option('buckmerce_plaid_last_webhook'), 'Rejected webhooks are never recorded as verified.');

// Key retrieval failure: rejected (retryable), never skipped.
$mock::fail_next('/webhook_verification_key/get', 'key_fail');
delete_transient('bmfp_jwk_sandbox_' . substr(hash('sha256', $mock::KID), 0, 32));
$response = bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body);
bmfp_assert_same(503, $response['status'], 'Key retrieval failure → 503 (Plaid retries).');
bmfp_assert_same(0, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Key failure must not enqueue work.');

// Environment confusion: a verified production webhook for a sandbox store is ignored.
$production = (string) wp_json_encode(array('webhook_type' => 'TRANSFER', 'webhook_code' => 'TRANSFER_EVENTS_UPDATE', 'environment' => 'production'));
$response = bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($production)), $production);
bmfp_assert_same(200, $response['status'], 'Other-environment webhook acknowledged.');
bmfp_assert_same(0, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Other-environment webhook ignored.');

// Unrelated verified webhooks are acknowledged without work.
$item = (string) wp_json_encode(array('webhook_type' => 'ITEM', 'webhook_code' => 'ERROR', 'environment' => 'sandbox'));
bmfp_assert_same(200, bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($item)), $item)['status'], 'Unrelated webhook acknowledged.');
bmfp_assert_same(0, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Unrelated webhook enqueues nothing.');

$after = array(bmfp_reload($order)->get_status(), bmfp_meta_state($order), (int) bmfp_reload($order)->get_meta(OrderMeta::LAST_EVENT_ID, true));
bmfp_assert_same($before, $after, 'No invalid or unrelated webhook mutated the order.');

// Valid webhook → exactly one job; duplicates coalesce; processing updates the order.
bmfp_assert_same(200, bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body)['status'], 'Valid webhook accepted.');
bmfp_assert_same(200, bmfp_rest('/webhook', array(), array('plaid-verification' => $mock::sign($body)), $body)['status'], 'Duplicate webhook accepted.');
bmfp_assert_same(1, bmfp_pending_actions(Scheduler::EVENT_SYNC_HOOK), 'Duplicate webhooks coalesce.');
$verified = get_option('buckmerce_plaid_last_webhook');
bmfp_assert(is_array($verified) && 'TRANSFER_EVENTS_UPDATE' === $verified['code'] && 'event_sync_queued' === $verified['outcome'] && 'sandbox' === $verified['environment'], 'Diagnostics record the last verified webhook: ' . wp_json_encode($verified));
bmfp_assert(str_contains(\Buckmerce\Plaid\Admin\DiagnosticsPage::report()['Last verified webhook'], 'TRANSFER_EVENTS_UPDATE'), 'Diagnostics report shows the last verified webhook.');
bmfp_run_scheduled(Scheduler::EVENT_SYNC_HOOK);
bmfp_assert(bmfp_reload($order)->is_paid(), 'Verified webhook → event sync → order paid.');

// GET is not an allowed method.
$get = rest_do_request(new WP_REST_Request('GET', '/buckmerce-plaid/v1/webhook'));
bmfp_assert_same(404, $get->get_status(), 'Webhook route accepts POST only.');
WP_CLI::success('Buckmerce webhook REST suite passed (HPOS=' . (getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ?: '?') . ').');
