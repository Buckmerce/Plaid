<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\PaymentAttemptBusyException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentSnapshot;
use PayBridge\Plaid\Settings\Settings;

/** PayBridge panel on the WooCommerce order screen (HPOS and legacy) with manual "Sync with Plaid". */
final class OrderMetaBox
{
    public const SYNC_ACTION = 'pbfp_sync_order';

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render'));
        add_action('admin_post_' . self::SYNC_ACTION, array($this, 'sync'));
    }

    public function render(\WC_Order $order): void
    {
        if (Settings::GATEWAY_ID !== $order->get_payment_method() || ! current_user_can('manage_woocommerce')) {
            return;
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        $failure = trim((string) $order->get_meta(OrderMeta::RETURN_CODE, true) . ' ' . (string) $order->get_meta(OrderMeta::FAILURE_CODE, true));
        $review = (string) $order->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true);
        $rows = array(
            __('Environment', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::ENVIRONMENT, true),
            __('Payment state', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true),
            __('Transfer Intent ID', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true),
            __('Transfer Intent status', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_STATUS, true),
            __('Transfer ID', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::TRANSFER_ID, true),
            __('Transfer status', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::TRANSFER_STATUS, true),
            __('Amount', 'paybridge-for-plaid') => null !== $snapshot ? $snapshot->amount . ' ' . $snapshot->currency : '',
            __('Last synchronized', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::LAST_SYNC_AT, true),
            __('Last event ID', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::LAST_EVENT_ID, true),
            __('Request ID', 'paybridge-for-plaid') => (string) $order->get_meta(OrderMeta::REQUEST_ID, true),
            __('Failure / return reason', 'paybridge-for-plaid') => $failure,
            __('Manual review reason', 'paybridge-for-plaid') => $review,
        );
        echo '<div class="pbfp-order-panel" style="clear:both;padding-top:12px"><h3>' . esc_html__('PayBridge for Plaid', 'paybridge-for-plaid') . '</h3>';
        $notice_key = 'pbfp_sync_notice_' . get_current_user_id() . '_' . $order->get_id();
        $notice = get_transient($notice_key);
        if (is_array($notice) && isset($notice['type'], $notice['message'])) {
            echo '<div class="notice inline ' . esc_attr('success' === $notice['type'] ? 'notice-success' : 'notice-error') . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
            delete_transient($notice_key);
        }
        if ('' !== (string) $order->get_meta(OrderMeta::RETURN_CODE, true) || 'returned' === (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true)) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('ACH return: the bank payment for this order was reversed.', 'paybridge-for-plaid') . '</strong></p></div>';
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            if ('' !== $value) {
                echo '<tr><th>' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>';
            }
        }
        echo '</tbody></table>';
        if ('' !== (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true)) {
            $url = wp_nonce_url(add_query_arg(array('action' => self::SYNC_ACTION, 'order_id' => $order->get_id()), admin_url('admin-post.php')), self::SYNC_ACTION . '_' . $order->get_id());
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Sync with Plaid', 'paybridge-for-plaid') . '</a></p>';
        }
        echo '</div>';
    }

    public function sync(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to synchronize payments.', 'paybridge-for-plaid'), '', array('response' => 403));
        }
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        check_admin_referer(self::SYNC_ACTION . '_' . $order_id);
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            wp_die(esc_html__('The order is not a PayBridge order.', 'paybridge-for-plaid'), '', array('response' => 404));
        }
        try {
            ( new Container() )->synchronizer()->sync($order);
            $notice = array('type' => 'success', 'message' => __('Synchronized with Plaid.', 'paybridge-for-plaid'));
        } catch (PaymentAttemptBusyException $exception) {
            $notice = array('type' => 'error', 'message' => __('The payment is being processed right now. Try again in a moment.', 'paybridge-for-plaid'));
        } catch (\Throwable $exception) {
            ( new Logger() )->log('warning', 'manual_sync_failed', array('order_id' => $order_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            $notice = array('type' => 'error', 'message' => __('Plaid could not be reached or returned an error. See the PayBridge logs.', 'paybridge-for-plaid'));
        }
        set_transient('pbfp_sync_notice_' . get_current_user_id() . '_' . $order_id, $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }
}
