<?php
/**
 * Plugin Name: PayBridge test — webhook capture
 * Description: Disposable Sandbox sites only. Records every request that reaches the PayBridge
 *              webhook route (raw body, Plaid-Verification header, user agent, response status)
 *              so the Sandbox gate can prove delivery and replay genuine Plaid webhooks.
 */

declare(strict_types=1);

if (! defined('PAYBRIDGE_PLAID_SANDBOX_TEST') || ! PAYBRIDGE_PLAID_SANDBOX_TEST) {
    return;
}

add_filter(
    'rest_request_after_callbacks',
    static function ($response, $handler, WP_REST_Request $request) {
        if ('/paybridge-for-plaid/v1/webhook' !== $request->get_route() || 'POST' !== $request->get_method()) {
            return $response;
        }
        $status = $response instanceof WP_REST_Response ? $response->get_status() : (is_wp_error($response) ? 500 : 200);
        $captures = get_option('pbfp_test_webhook_captures', array());
        $captures = is_array($captures) ? $captures : array();
        $captures[] = array(
            'at' => time(),
            'status' => $status,
            'user_agent' => substr((string) $request->get_header('user_agent'), 0, 120),
            'jwt' => (string) $request->get_header('plaid_verification'),
            'body' => base64_encode((string) $request->get_body()),
        );
        update_option('pbfp_test_webhook_captures', array_slice($captures, -100), false);
        return $response;
    },
    10,
    3
);
