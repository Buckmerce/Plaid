<?php
/**
 * Test-only endpoints for the deterministic browser suite (MU plugin). Inert
 * unless PAYBRIDGE_PLAID_TEST_DATABASE and PAYBRIDGE_PLAID_BROWSER_TEST are true.
 */

declare(strict_types=1);

if (! defined('PAYBRIDGE_PLAID_TEST_DATABASE') || true !== PAYBRIDGE_PLAID_TEST_DATABASE || ! defined('PAYBRIDGE_PLAID_BROWSER_TEST') || true !== PAYBRIDGE_PLAID_BROWSER_TEST) {
    return;
}

add_action('init', static function (): void {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- disposable test site only.
    if (isset($_GET['pbfp_e2e_authorize'])) {
        $token = sanitize_text_field(wp_unslash($_GET['pbfp_e2e_authorize']));
        $outcome = isset($_GET['outcome']) ? sanitize_key(wp_unslash($_GET['outcome'])) : 'success';
        try {
            $transfer = PayBridge_Test_Plaid_Mock::authorize($token, $outcome);
            wp_send_json(array('ok' => true, 'transfer_id' => $transfer));
        } catch (Throwable $exception) {
            wp_send_json(array('ok' => false, 'error' => $exception->getMessage()), 400);
        }
    }
    if (isset($_GET['pbfp_e2e_order'])) {
        $order = wc_get_order(absint($_GET['pbfp_e2e_order']));
        if (! $order instanceof WC_Order) {
            wp_send_json(array('ok' => false), 404);
        }
        if (isset($_GET['advance']) && '' !== (string) $order->get_meta('_pbfp_transfer_id', true)) {
            PayBridge_Test_Plaid_Mock::advance((string) $order->get_meta('_pbfp_transfer_id', true));
            ( new PayBridge\Plaid\Container() )->event_sync()->run();
            $order = wc_get_order($order->get_id());
        }
        if (isset($_GET['cancel']) && ! $order->is_paid()) {
            $order->update_status('cancelled', 'E2E: cancelled by the store.');
            $order = wc_get_order($order->get_id());
        }
        if (isset($_GET['return']) && '' !== (string) $order->get_meta('_pbfp_transfer_id', true)) {
            PayBridge_Test_Plaid_Mock::add_event((string) $order->get_meta('_pbfp_transfer_id', true), 'returned', 'R01');
            ( new PayBridge\Plaid\Container() )->event_sync()->run();
            $order = wc_get_order($order->get_id());
        }
        if (isset($_GET['refund_event']) && is_array($refunds = PayBridge_Test_Plaid_Mock::state()['refunds'] ?? null)) {
            foreach ($refunds as $refund_id => $refund) {
                if ('pending' === $refund['status']) {
                    PayBridge_Test_Plaid_Mock::add_refund_event((string) $refund_id, 'refund.posted');
                }
            }
            ( new PayBridge\Plaid\Container() )->event_sync()->run();
        }
        wp_send_json(array(
            'ok' => true,
            'status' => $order->get_status(),
            'paid' => $order->is_paid(),
            'payment_state' => (string) $order->get_meta('_pbfp_payment_state', true),
            'transfer_id' => (string) $order->get_meta('_pbfp_transfer_id', true),
            'intent_id' => (string) $order->get_meta('_pbfp_transfer_intent_id', true),
            'creates' => count(PayBridge_Test_Plaid_Mock::calls('/transfer/intent/create')),
            'refund_creates' => count(PayBridge_Test_Plaid_Mock::calls('/transfer/refund/create')),
            'edit_url' => $order->get_edit_order_url(),
            'pay_url' => $order->get_checkout_payment_url(),
            'needs_payment' => $order->needs_payment(),
            'link_tokens' => count(PayBridge_Test_Plaid_Mock::calls('/link/token/create')),
        ));
    }
    if (isset($_GET['pbfp_e2e_checkout'])) {
        $page = 'blocks' === sanitize_key(wp_unslash($_GET['pbfp_e2e_checkout'])) ? (int) get_option('pbfp_e2e_blocks_page') : (int) get_option('pbfp_e2e_classic_page');
        update_option('woocommerce_checkout_page_id', $page);
        wp_send_json(array('ok' => $page > 0));
    }
    if (isset($_GET['pbfp_e2e_config'])) {
        // Store configuration variants for the checkout release gate (restored with "reset").
        $variant = sanitize_key(wp_unslash($_GET['pbfp_e2e_config']));
        $saved = get_option('pbfp_e2e_saved_settings');
        if (! is_array($saved)) {
            $saved = get_option('woocommerce_paybridge_plaid_settings');
            update_option('pbfp_e2e_saved_settings', $saved, false);
        }
        update_option('woocommerce_currency', 'eur' === $variant ? 'EUR' : 'USD');
        update_option('woocommerce_paybridge_plaid_settings', 'secretless' === $variant ? array('secret' => '') + $saved : $saved);
        wp_send_json(array('ok' => true, 'variant' => $variant));
    }
    if (isset($_GET['pbfp_e2e_customer'])) {
        $login = 'pbfp_e2e_customer';
        $user = get_user_by('login', $login);
        $user_id = $user instanceof WP_User ? $user->ID : wp_insert_user(array('user_login' => $login, 'user_pass' => 'local-customer-password', 'user_email' => 'customer@example.com', 'role' => 'customer', 'first_name' => 'Casey', 'last_name' => 'Customer'));
        wp_send_json(array('ok' => ! is_wp_error($user_id), 'login' => $login, 'password' => 'local-customer-password'));
    }
    if (isset($_GET['pbfp_e2e_latest_order'])) {
        $ids = wc_get_orders(array('limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids', 'payment_method' => 'paybridge_plaid'));
        wp_send_json(array('order_id' => (int) ($ids[0] ?? 0)));
    }
    // phpcs:enable
});
