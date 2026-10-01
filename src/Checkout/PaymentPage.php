<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Checkout;

use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Payment\ReturnRetryPolicy;
use PayBridge\Plaid\Plaid\TransferIntent\TransferIntentRequest;
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
        add_action('before_woocommerce_pay_form', array($this, 'returned_payment_notice'), 10, 1);
        add_filter('woocommerce_cancel_unpaid_order', array($this, 'keep_order_during_authorization'), 10, 2);
    }

    /**
     * WooCommerce order-pay form: Pay by Bank is not offered for an order whose bank payment was
     * returned (ADR-0019), so tell the customer why instead of leaving them guessing.
     *
     * @param mixed $order
     */
    public function returned_payment_notice($order): void
    {
        if (! $order instanceof \WC_Order || ! ReturnRetryPolicy::for_order($order)->is_blocked()) {
            return;
        }
        echo '<div class="woocommerce-info pbfp-returned-notice" role="status">' . esc_html(ReturnRetryPolicy::customer_message()) . '</div>';
    }

    public function render(int $order_id): void
    {
        $order = wc_get_order($order_id);
        $order_key = isset($_GET['key']) && is_string($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce validates the order key for this screen.
        if (! $order instanceof \WC_Order || ! $this->access->can_access($order, $order_key)) {
            echo '<div class="woocommerce-error pbfp-access-denied" role="alert">' . esc_html__('This payment session has expired. Please open the payment link from your order confirmation email or log in to your account to pay for this order.', 'paybridge-for-plaid') . '</div>';
            return;
        }

        $settings = Settings::load();
        $name_missing = '' === TransferIntentRequest::legal_name((string) $order->get_billing_first_name(), (string) $order->get_billing_last_name());
        $returned = ReturnRetryPolicy::for_order($order)->is_blocked();
        if (! $name_missing && ! $returned) {
            $this->enqueue($order, $order_key);
        }
        ?>
        <section class="pbfp-payment" aria-labelledby="pbfp-payment-title">
            <h2 id="pbfp-payment-title" class="pbfp-payment__title">
                <?php echo esc_html($settings->title()); ?>
                <?php if (! $settings->is_production()) : ?>
                    <span class="pbfp-payment__sandbox"><?php esc_html_e('Sandbox — test mode, no real money', 'paybridge-for-plaid'); ?></span>
                <?php endif; ?>
            </h2>
            <dl class="pbfp-payment__summary">
                <div><dt><?php esc_html_e('Order', 'paybridge-for-plaid'); ?></dt><dd>#<?php echo esc_html((string) $order->get_order_number()); ?></dd></div>
                <div><dt><?php esc_html_e('Amount', 'paybridge-for-plaid'); ?></dt><dd><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd></div>
            </dl>
            <p class="pbfp-payment__description"><?php echo esc_html($settings->description()); ?></p>
            <h3 class="pbfp-payment__steps-title"><?php esc_html_e('What happens next', 'paybridge-for-plaid'); ?></h3>
            <ol class="pbfp-payment__steps">
                <li><?php esc_html_e('A secure window from Plaid opens. Choose your bank and sign in there.', 'paybridge-for-plaid'); ?></li>
                <li><?php esc_html_e('Select the account to pay from and confirm the amount.', 'paybridge-for-plaid'); ?></li>
                <li><?php esc_html_e('We confirm the payment and show your order. Bank payments usually take 1–3 business days to complete; we update your order when they do.', 'paybridge-for-plaid'); ?></li>
            </ol>
            <?php if ($returned) : ?>
                <p class="woocommerce-error pbfp-payment__blocked" role="alert"><?php echo esc_html(ReturnRetryPolicy::customer_message()); ?></p>
            <?php elseif ($name_missing) : ?>
                <p class="woocommerce-error pbfp-payment__blocked" role="alert"><?php esc_html_e('This order has no account holder name, which is required to pay by bank. Please contact the store or place a new order with your legal first and last name.', 'paybridge-for-plaid'); ?></p>
            <?php else : ?>
                <button type="button" class="button alt pbfp-payment__button" data-pbfp-pay aria-describedby="pbfp-payment-status" disabled><?php esc_html_e('Connect bank and pay', 'paybridge-for-plaid'); ?></button>
                <p class="pbfp-payment__status" id="pbfp-payment-status" data-pbfp-status role="status" aria-live="polite" aria-atomic="true"></p>
            <?php endif; ?>
            <p class="pbfp-payment__disclosure"><?php esc_html_e('Your bank connection and payment authorization are handled securely by Plaid. This store never sees your bank login credentials.', 'paybridge-for-plaid'); ?></p>
            <noscript><p class="woocommerce-error"><?php esc_html_e('JavaScript is required to connect your bank.', 'paybridge-for-plaid'); ?></p></noscript>
        </section>
        <?php
    }

    private function enqueue(\WC_Order $order, string $order_key): void
    {
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
                'preparing' => __('Preparing a secure bank connection…', 'paybridge-for-plaid'),
                'opening' => __('Opening the secure bank connection…', 'paybridge-for-plaid'),
                'verifying' => __('Confirming your payment…', 'paybridge-for-plaid'),
                'submitted' => __('Payment submitted. Taking you to your order…', 'paybridge-for-plaid'),
                'exited' => __('The bank connection was closed before the payment was completed. No payment was made. You can try again.', 'paybridge-for-plaid'),
                'incomplete' => __('Your bank payment was not completed. You can try again.', 'paybridge-for-plaid'),
                'insufficient' => __('Your bank reported insufficient funds. You can try again or use another account.', 'paybridge-for-plaid'),
                'failed' => __('Your bank payment could not be authorized. You can try again.', 'paybridge-for-plaid'),
                'unverified' => __('We could not confirm your payment yet. Please wait a moment…', 'paybridge-for-plaid'),
                'review' => __('Your payment needs review by the store. Please contact the store.', 'paybridge-for-plaid'),
                'error' => __('The bank payment could not be started. Please try again.', 'paybridge-for-plaid'),
                'unavailable' => __('The secure bank connection could not be loaded. Check your connection and try again.', 'paybridge-for-plaid'),
                'notPayable' => __('Pay by Bank is not available for this order right now. Please contact the store or choose another payment method.', 'paybridge-for-plaid'),
                'missingName' => __('This order has no account holder name. Please contact the store.', 'paybridge-for-plaid'),
                'returned' => ReturnRetryPolicy::customer_message(),
                'rateLimited' => __('Too many attempts. Please wait a few minutes and try again.', 'paybridge-for-plaid'),
                'retry' => __('Try again', 'paybridge-for-plaid'),
            ),
        );
        wp_add_inline_script('paybridge-plaid-payment', 'window.paybridgePlaidPayment = ' . wp_json_encode($config) . ';', 'before');
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
