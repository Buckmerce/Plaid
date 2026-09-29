<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Checkout;

use PayBridge\Plaid\Settings\Settings;

/**
 * Authorizes customer access to one order's payment page and endpoints.
 *
 * A numeric order ID is never sufficient: the caller must present the order
 * key AND be the order owner (logged in) or, for guest orders, hold the
 * session grant created by process_payment() in the same WooCommerce session.
 */
final class PaymentAccess
{
    private const SESSION_KEY = 'pbfp_payment_grants';
    private const MAX_GRANTS = 20;

    public function grant(\WC_Order $order): void
    {
        if (0 !== $order->get_customer_id()) {
            return;
        }
        $session = $this->session();
        $fingerprint = $this->fingerprint($order);
        if (null === $session || '' === $fingerprint) {
            return;
        }
        $grants = $session->get(self::SESSION_KEY, array());
        $grants = is_array($grants) ? $grants : array();
        unset($grants[(string) $order->get_id()]);
        $grants[(string) $order->get_id()] = $fingerprint;
        if (count($grants) > self::MAX_GRANTS) {
            $grants = array_slice($grants, -self::MAX_GRANTS, null, true);
        }
        $session->set(self::SESSION_KEY, $grants);
    }

    public function can_access(\WC_Order $order, string $order_key): bool
    {
        if (Settings::GATEWAY_ID !== $order->get_payment_method()) {
            return false;
        }
        $expected_key = (string) $order->get_order_key();
        if ('' === $expected_key || '' === $order_key || ! hash_equals($expected_key, $order_key)) {
            return false;
        }
        $customer_id = $order->get_customer_id();
        if ($customer_id > 0) {
            return is_user_logged_in() && get_current_user_id() === $customer_id;
        }
        $session = $this->session();
        if (null === $session) {
            return false;
        }
        $grants = $session->get(self::SESSION_KEY, array());
        $stored = is_array($grants) ? ($grants[(string) $order->get_id()] ?? null) : null;
        $fingerprint = $this->fingerprint($order);
        return is_string($stored) && '' !== $fingerprint && hash_equals($fingerprint, $stored);
    }

    public static function nonce_action(int $order_id): string
    {
        return 'pbfp_payment_' . $order_id;
    }

    private function fingerprint(\WC_Order $order): string
    {
        $key = (string) $order->get_order_key();
        return '' === $key ? '' : hash_hmac('sha256', $order->get_id() . '|' . $key, wp_salt('auth'));
    }

    /**
     * WooCommerce does not start its session for REST requests, so it is
     * initialised here (reading the customer's existing session cookie) before
     * session grants or session-bound guest nonces are checked.
     */
    private function session(): ?\WC_Session
    {
        if (! function_exists('WC')) {
            return null;
        }
        $woocommerce = WC();
        /** @var \WC_Session|null $session */
        $session = $woocommerce->session;
        if (! $session instanceof \WC_Session && method_exists($woocommerce, 'initialize_session')) {
            $woocommerce->initialize_session();
            /** @var \WC_Session|null $session */
            $session = $woocommerce->session;
        }
        return $session instanceof \WC_Session ? $session : null;
    }

    /** Starts the WooCommerce session early so session-bound guest nonces verify consistently. */
    public function ensure_session(): void
    {
        $this->session();
    }
}
