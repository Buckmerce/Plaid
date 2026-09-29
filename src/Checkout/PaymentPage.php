<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Checkout;

use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\Settings\Settings;

/**
 * The PayBridge payment page, rendered on WooCommerce's native order-pay
 * receipt screen (woocommerce_receipt_{gateway}). WooCommerce has already
 * validated the order key; PaymentAccess additionally enforces ownership.
 */
final class PaymentPage
{
    public const PLAID_LINK_SCRIPT = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';

    public function __construct(private readonly PaymentAccess $access = new PaymentAccess())
    {
    }

    public function register(): void
    {
        add_action('woocommerce_receipt_' . Settings::GATEWAY_ID, array($this, 'render'));
        add_filter('woocommerce_cancel_unpaid_order', array($this, 'keep_order_during_authorization'), 10, 2);
    }

    public function render(int $order_id): void
    {
        $order = wc_get_order($order_id);
        $order_key = isset($_GET['key']) && is_string($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce validates the order key for this screen.
        if (! $order instanceof \WC_Order || ! $this->access->can_access($order, $order_key)) {
            echo '<div class="woocommerce-error pbfp-access-denied" role="alert">' . esc_html__('This payment session has expired. Please open the payment link from your order confirmation email or log in to your account to pay for this order.', 'paybridge-for-plaid') . '</div>';
            return;
        }

        wp_enqueue_script('paybridge-plaid-link', self::PLAID_LINK_SCRIPT, array(), null, true); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Plaid requires loading Link from its CDN without modification.
        wp_enqueue_script('paybridge-plaid-payment', PAYBRIDGE_PLAID_URL . 'assets/build/payment-page.js', array('paybridge-plaid-link'), PAYBRIDGE_PLAID_VERSION, true);
        wp_enqueue_style('paybridge-plaid-payment', PAYBRIDGE_PLAID_URL . 'assets/payment-page.css', array(), PAYBRIDGE_PLAID_VERSION);
        $config = array(
            'orderId' => $order->get_id(),
            'orderKey' => $order_key,
            'paymentNonce' => wp_create_nonce(PaymentAccess::nonce_action($order->get_id())),
            // Guests authenticate with the order key + session-bound payment nonce; sending a
            // wp_rest nonce for a guest would fail WordPress' cookie check in REST requests.
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'linkTokenUrl' => rest_url(RestRoutes::NAMESPACE . '/link-token'),
            'completeUrl' => rest_url(RestRoutes::NAMESPACE . '/complete'),
            'returnUrl' => $order->get_checkout_order_received_url(),
            'i18n' => array(
                'preparing' => __('Preparing secure bank connection…', 'paybridge-for-plaid'),
                'opening' => __('Opening your bank connection…', 'paybridge-for-plaid'),
                'verifying' => __('Confirming your payment with Plaid…', 'paybridge-for-plaid'),
                'submitted' => __('Payment submitted. Redirecting to your order…', 'paybridge-for-plaid'),
                'exited' => __('The bank connection was closed before the payment was completed. You can try again.', 'paybridge-for-plaid'),
                'incomplete' => __('Your bank payment was not completed. You can try again.', 'paybridge-for-plaid'),
                'insufficient' => __('Your bank reported insufficient funds. You can try again or use another account.', 'paybridge-for-plaid'),
                'failed' => __('Your bank payment could not be authorized. You can try again.', 'paybridge-for-plaid'),
                'unverified' => __('We could not confirm your payment yet. Please wait a moment…', 'paybridge-for-plaid'),
                'review' => __('Your payment needs review by the store. Please contact the store.', 'paybridge-for-plaid'),
                'error' => __('Something went wrong while starting the bank payment. Please try again.', 'paybridge-for-plaid'),
                'unavailable' => __('Secure bank connection could not be loaded. Check your connection and try again.', 'paybridge-for-plaid'),
                'retry' => __('Try again', 'paybridge-for-plaid'),
            ),
        );
        wp_add_inline_script('paybridge-plaid-payment', 'window.paybridgePlaidPayment = ' . wp_json_encode($config) . ';', 'before');

        $settings = Settings::load();
        ?>
        <section class="pbfp-payment" aria-labelledby="pbfp-payment-title">
            <h2 id="pbfp-payment-title" class="pbfp-payment__title"><?php echo esc_html($settings->title()); ?></h2>
            <dl class="pbfp-payment__summary">
                <div><dt><?php esc_html_e('Order', 'paybridge-for-plaid'); ?></dt><dd>#<?php echo esc_html((string) $order->get_order_number()); ?></dd></div>
                <div><dt><?php esc_html_e('Amount', 'paybridge-for-plaid'); ?></dt><dd><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd></div>
            </dl>
            <p class="pbfp-payment__description"><?php echo esc_html($settings->description()); ?></p>
            <button type="button" class="button alt pbfp-payment__button" data-pbfp-pay disabled><?php esc_html_e('Connect bank and pay', 'paybridge-for-plaid'); ?></button>
            <p class="pbfp-payment__status" data-pbfp-status role="status" aria-live="polite"></p>
            <p class="pbfp-payment__disclosure"><?php esc_html_e('Your bank connection and payment authorization are handled securely by Plaid. This store never sees your bank login credentials.', 'paybridge-for-plaid'); ?></p>
            <noscript><p class="woocommerce-error"><?php esc_html_e('JavaScript is required to connect your bank.', 'paybridge-for-plaid'); ?></p></noscript>
        </section>
        <?php
    }

    /**
     * WooCommerce cancels unpaid checkout orders after the hold-stock window.
     * While a Link token for the order could still be used, cancelling would
     * let the customer pay for a cancelled order, so keep it pending.
     */
    public function keep_order_during_authorization(bool $cancel, \WC_Order $order): bool
    {
        if (! $cancel || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            return $cancel;
        }
        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (in_array($state, array(PaymentState::INTENT_CREATED, PaymentState::INTENT_PENDING), true) && PaymentAttemptService::authorization_window_open($order)) {
            return false;
        }
        return $cancel;
    }
}
