<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\PaymentAttemptBusyException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\AttemptHistory;
use PayBridge\Plaid\Payment\ManualActions;
use PayBridge\Plaid\Payment\MonitoringPolicy;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentAlerts;
use PayBridge\Plaid\Payment\PaymentMonitor;
use PayBridge\Plaid\Payment\PaymentSnapshot;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Payment\ReturnRetryPolicy;
use PayBridge\Plaid\Refund\RefundState;
use PayBridge\Plaid\Settings\Settings;

/**
 * PayBridge panel on the WooCommerce order screen (HPOS and legacy): current attempt, Plaid
 * identifiers and dates, return windows, refunds, earlier attempts and alerts, plus explicit
 * actions. Rendering reads local data only; Plaid is contacted only by an action.
 */
final class OrderMetaBox
{
    public const SYNC_ACTION = 'pbfp_sync_order';
    public const CANCEL_ACTION = 'pbfp_cancel_transfer';
    public const REVIEW_ACTION = 'pbfp_resolve_review';

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render'));
        add_action('admin_post_' . self::SYNC_ACTION, array($this, 'sync'));
        add_action('admin_post_' . self::CANCEL_ACTION, array($this, 'cancel'));
        add_action('admin_post_' . self::REVIEW_ACTION, array($this, 'resolve_review'));
    }

    public function render(\WC_Order $order): void
    {
        if (Settings::GATEWAY_ID !== $order->get_payment_method() || ! current_user_can('manage_woocommerce')) {
            return;
        }
        $meta = static fn (string $key): string => (string) $order->get_meta($key, true);
        $snapshot = PaymentSnapshot::from_json($meta(OrderMeta::PAYMENT_SNAPSHOT));
        $state = $meta(OrderMeta::PAYMENT_STATE);
        $windows = null;
        if (null !== $snapshot && in_array($state, array(PaymentState::SETTLED, PaymentState::FUNDS_AVAILABLE, PaymentState::MANUAL_REVIEW, PaymentState::RETURNED), true) && '' !== $meta(OrderMeta::TRANSFER_ID)) {
            $windows = MonitoringPolicy::return_windows(PaymentMonitor::input($order, $state, $snapshot, time()));
        }
        $refunds = ( new Container() )->refunds();
        $records = $refunds->for_order($order);
        $eligibility = $refunds->eligibility($order);
        $rows = array(
            __('PayBridge state', 'paybridge-for-plaid') => $state,
            __('Environment', 'paybridge-for-plaid') => $meta(OrderMeta::ENVIRONMENT),
            __('Payment attempt', 'paybridge-for-plaid') => null !== $snapshot ? $snapshot->attempt_id : '',
            __('Attempt created', 'paybridge-for-plaid') => null !== $snapshot ? $snapshot->created_at : '',
            __('Amount', 'paybridge-for-plaid') => null !== $snapshot ? $snapshot->amount . ' ' . $snapshot->currency : '',
            __('Transfer Intent ID', 'paybridge-for-plaid') => $meta(OrderMeta::TRANSFER_INTENT_ID),
            __('Transfer Intent status (Plaid)', 'paybridge-for-plaid') => $meta(OrderMeta::TRANSFER_INTENT_STATUS),
            __('Transfer ID', 'paybridge-for-plaid') => $meta(OrderMeta::TRANSFER_ID),
            __('Transfer status (Plaid)', 'paybridge-for-plaid') => $meta(OrderMeta::TRANSFER_STATUS),
            __('Transfer created', 'paybridge-for-plaid') => $meta(OrderMeta::TRANSFER_CREATED_AT),
            __('Settled', 'paybridge-for-plaid') => $meta(OrderMeta::SETTLED_AT),
            __('Funds available', 'paybridge-for-plaid') => '' !== $meta(OrderMeta::FUNDS_AVAILABLE_AT) ? $meta(OrderMeta::FUNDS_AVAILABLE_AT) : ('' !== $meta(OrderMeta::EXPECTED_FUNDS_AVAILABLE_DATE) ? sprintf(/* translators: %s: date */ __('expected %s', 'paybridge-for-plaid'), $meta(OrderMeta::EXPECTED_FUNDS_AVAILABLE_DATE)) : ''),
            __('Standard return window', 'paybridge-for-plaid') => '' !== $meta(OrderMeta::STANDARD_RETURN_WINDOW) ? $meta(OrderMeta::STANDARD_RETURN_WINDOW) : (null !== $windows ? sprintf(/* translators: %s: date */ __('about %s (estimated)', 'paybridge-for-plaid'), gmdate('Y-m-d', $windows[0])) : ''),
            __('Unauthorized return window', 'paybridge-for-plaid') => '' !== $meta(OrderMeta::UNAUTHORIZED_RETURN_WINDOW) ? $meta(OrderMeta::UNAUTHORIZED_RETURN_WINDOW) : (null !== $windows ? sprintf(/* translators: %s: date */ __('about %s (estimated)', 'paybridge-for-plaid'), gmdate('Y-m-d', $windows[1])) : ''),
            __('Returned', 'paybridge-for-plaid') => $meta(OrderMeta::RETURNED_AT),
            __('Return code', 'paybridge-for-plaid') => $meta(OrderMeta::RETURN_CODE),
            __('Failure code', 'paybridge-for-plaid') => $meta(OrderMeta::FAILURE_CODE),
            __('Failure / return reason', 'paybridge-for-plaid') => $meta(OrderMeta::FAILURE_DESCRIPTION),
            __('Manual review reason', 'paybridge-for-plaid') => $meta(OrderMeta::MANUAL_REVIEW_REASON),
            __('Last synchronized', 'paybridge-for-plaid') => $meta(OrderMeta::LAST_SYNC_AT),
            __('Last event ID', 'paybridge-for-plaid') => $meta(OrderMeta::LAST_EVENT_ID),
            __('Plaid request ID', 'paybridge-for-plaid') => $meta(OrderMeta::REQUEST_ID),
            __('Refunded through Plaid', 'paybridge-for-plaid') => array() === $records ? '' : '$' . $eligibility->refunded . ($eligibility->allowed ? ' · ' . sprintf(/* translators: %s: amount */ __('$%s refundable', 'paybridge-for-plaid'), $eligibility->remaining) : ''),
        );
        echo '<div class="pbfp-order-panel" style="clear:both;padding-top:12px"><h3>' . esc_html__('PayBridge for Plaid', 'paybridge-for-plaid') . '</h3>';
        $notice_key = 'pbfp_sync_notice_' . get_current_user_id() . '_' . $order->get_id();
        $notice = get_transient($notice_key);
        if (is_array($notice) && isset($notice['type'], $notice['message'])) {
            echo '<div class="notice inline ' . esc_attr('success' === $notice['type'] ? 'notice-success' : 'notice-error') . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
            delete_transient($notice_key);
        }
        foreach (( new PaymentAlerts() )->for_order($order->get_id()) as $alert) {
            $critical = in_array($alert['type'], PaymentAlerts::CRITICAL, true);
            echo '<div class="notice inline ' . esc_attr($critical ? 'notice-error' : 'notice-warning') . '"><p><strong>' . esc_html(PaymentAlerts::message($alert)) . '</strong></p></div>';
        }
        $retry = ReturnRetryPolicy::for_order($order);
        if (PaymentState::RETURNED === $state) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Bank payment returned: the customer\'s bank reversed this payment. The original payment details are kept below.', 'paybridge-for-plaid') . '</strong></p>';
            if ($retry->is_blocked()) {
                echo '<p class="pbfp-retry-policy">' . esc_html(ReturnRetryPolicy::merchant_explanation($retry)) . '</p>';
            }
            echo '</div>';
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            if ('' !== $value) {
                echo '<tr><th scope="row">' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>';
            }
        }
        echo '</tbody></table>';
        $this->render_refunds($records);
        $this->render_attempts(AttemptHistory::all($order));
        $this->render_actions($order, $state, $meta);
        echo '</div>';
    }

    /** @param list<\PayBridge\Plaid\Refund\RefundRecord> $records */
    private function render_refunds(array $records): void
    {
        if (array() === $records) {
            return;
        }
        echo '<h4>' . esc_html__('Refunds', 'paybridge-for-plaid') . '</h4><table class="widefat striped"><thead><tr>';
        foreach (array(__('Amount', 'paybridge-for-plaid'), __('Status', 'paybridge-for-plaid'), __('Plaid refund ID', 'paybridge-for-plaid'), __('WooCommerce refund', 'paybridge-for-plaid'), __('Code', 'paybridge-for-plaid'), __('Updated', 'paybridge-for-plaid')) as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($records as $record) {
            $origin = 'external' === $record->origin ? ' (' . __('created outside WooCommerce', 'paybridge-for-plaid') . ')' : '';
            echo '<tr' . (RefundState::is_failure($record->status) || RefundState::UNCERTAIN === $record->status ? ' class="pbfp-refund--problem"' : '') . '>';
            echo '<td>$' . esc_html($record->amount) . '</td><td><strong>' . esc_html($record->status) . '</strong>' . esc_html($origin) . '</td>';
            echo '<td><code>' . esc_html($record->refund_id) . '</code></td><td>' . esc_html($record->wc_refund_id > 0 ? '#' . $record->wc_refund_id : '—') . '</td>';
            echo '<td>' . esc_html($record->failure_code) . '</td><td>' . esc_html($record->updated_at) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param list<array<string, string>> $attempts */
    private function render_attempts(array $attempts): void
    {
        if (array() === $attempts) {
            return;
        }
        echo '<h4>' . esc_html__('Earlier payment attempts', 'paybridge-for-plaid') . '</h4><table class="widefat striped"><thead><tr>';
        foreach (array(__('Created', 'paybridge-for-plaid'), __('Amount', 'paybridge-for-plaid'), __('Final state', 'paybridge-for-plaid'), __('Transfer ID', 'paybridge-for-plaid'), __('Return / failure', 'paybridge-for-plaid'), __('Retired', 'paybridge-for-plaid')) as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_reverse($attempts) as $attempt) {
            $outcome = trim(($attempt['return_code'] ?? '') . ' ' . ($attempt['failure_code'] ?? '') . ' ' . ($attempt['returned_at'] ?? ''));
            echo '<tr><td>' . esc_html($attempt['created_at'] ?? '') . '</td><td>' . esc_html(($attempt['amount'] ?? '') . ' ' . ($attempt['currency'] ?? '')) . '</td>';
            echo '<td>' . esc_html($attempt['payment_state'] ?? '') . '</td><td><code>' . esc_html($attempt['transfer_id'] ?? '') . '</code></td>';
            echo '<td>' . esc_html($outcome) . '</td><td>' . esc_html(($attempt['retired_at'] ?? '') . ' (' . ($attempt['reason'] ?? '') . ')') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param callable(string): string $meta */
    private function render_actions(\WC_Order $order, string $state, callable $meta): void
    {
        $links = array();
        if ('' !== $meta(OrderMeta::TRANSFER_INTENT_ID)) {
            $links[] = '<a class="button" href="' . esc_url(self::action_url(self::SYNC_ACTION, $order)) . '">' . esc_html__('Sync with Plaid', 'paybridge-for-plaid') . '</a>';
        }
        if ('yes' === $meta(OrderMeta::TRANSFER_CANCELLABLE) && in_array($state, array(PaymentState::TRANSFER_CREATED, PaymentState::PENDING, PaymentState::MANUAL_REVIEW), true)) {
            $confirm = __('Cancel this bank payment at Plaid? The customer will not be charged and the order will be cancelled.', 'paybridge-for-plaid');
            $links[] = '<a class="button" href="' . esc_url(self::action_url(self::CANCEL_ACTION, $order)) . '" onclick="return window.confirm(' . esc_attr((string) wp_json_encode($confirm)) . ');">' . esc_html__('Cancel bank payment', 'paybridge-for-plaid') . '</a>';
        }
        if (PaymentState::MANUAL_REVIEW === $state) {
            foreach (array('fulfil' => __('Reviewed: fulfil manually', 'paybridge-for-plaid'), 'refunded' => __('Reviewed: refunded', 'paybridge-for-plaid'), 'contacted_customer' => __('Reviewed: customer contacted', 'paybridge-for-plaid')) as $decision => $label) {
                $links[] = '<a class="button" href="' . esc_url(self::action_url(self::REVIEW_ACTION, $order, array('decision' => $decision))) . '">' . esc_html($label) . '</a>';
            }
            if ('' === $meta(OrderMeta::TRANSFER_ID) && ReturnRetryPolicy::for_order($order)->allows_new_debit()) {
                $links[] = '<a class="button" href="' . esc_url(self::action_url(self::REVIEW_ACTION, $order, array('decision' => 'other', 'release' => '1'))) . '">' . esc_html__('Let the customer pay again', 'paybridge-for-plaid') . '</a>';
            }
        }
        if (array() !== $links) {
            echo '<p class="pbfp-order-actions">' . implode(' ', $links) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
        }
    }

    /** @param array<string, string> $args */
    private static function action_url(string $action, \WC_Order $order, array $args = array()): string
    {
        return wp_nonce_url(add_query_arg(array('action' => $action, 'order_id' => $order->get_id()) + $args, admin_url('admin-post.php')), $action . '_' . $order->get_id());
    }

    public function sync(): void
    {
        $order = $this->authorize(self::SYNC_ACTION);
        try {
            $container = new Container();
            $container->synchronizer()->sync($order);
            $container->refunds()->sync_order($order);
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof \WC_Order) {
                $container->monitor()->refresh($fresh);
            }
            $notice = array('type' => 'success', 'message' => __('Synchronized with Plaid.', 'paybridge-for-plaid'));
        } catch (PaymentAttemptBusyException $exception) {
            $notice = array('type' => 'error', 'message' => __('The payment is being processed right now. Try again in a moment.', 'paybridge-for-plaid'));
        } catch (\Throwable $exception) {
            ( new Logger() )->log('warning', 'manual_sync_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            $notice = array('type' => 'error', 'message' => __('Plaid could not be reached or returned an error. See the PayBridge logs.', 'paybridge-for-plaid'));
        }
        $this->finish($order, $notice);
    }

    public function cancel(): void
    {
        $order = $this->authorize(self::CANCEL_ACTION);
        try {
            $result = ( new Container() )->manual_actions()->cancel_transfer($order, get_current_user_id());
            $notice = 'cancelled' === $result
                ? array('type' => 'success', 'message' => __('The bank payment was cancelled at Plaid.', 'paybridge-for-plaid'))
                : array('type' => 'error', 'message' => __('Plaid no longer allows cancelling this bank payment (it was already sent to the bank). Refund it after it settles instead.', 'paybridge-for-plaid'));
        } catch (PaymentAttemptBusyException $exception) {
            $notice = array('type' => 'error', 'message' => __('The payment is being processed right now. Try again in a moment.', 'paybridge-for-plaid'));
        } catch (\Throwable $exception) {
            ( new Logger() )->log('warning', 'manual_cancel_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            $notice = array('type' => 'error', 'message' => __('The cancellation could not be confirmed with Plaid. Nothing was changed; use Sync with Plaid to check the payment.', 'paybridge-for-plaid'));
        }
        $this->finish($order, $notice);
    }

    public function resolve_review(): void
    {
        $order = $this->authorize(self::REVIEW_ACTION);
        $decision = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
        $release = isset($_GET['release']) && '1' === $_GET['release']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
        if (! in_array($decision, ManualActions::DECISIONS, true)) {
            wp_die(esc_html__('Unknown decision.', 'paybridge-for-plaid'), '', array('response' => 400));
        }
        try {
            $result = ( new Container() )->manual_actions()->resolve_review($order, $decision, $release, get_current_user_id());
            $notice = match ($result) {
                'released' => array('type' => 'success', 'message' => __('Review recorded. The customer can pay for this order again.', 'paybridge-for-plaid')),
                'recorded' => array('type' => 'success', 'message' => __('Review decision recorded.', 'paybridge-for-plaid')),
                default => array('type' => 'error', 'message' => __('This payment cannot be released: Plaid shows it may still be authorized or it already created a transfer.', 'paybridge-for-plaid')),
            };
        } catch (\Throwable $exception) {
            ( new Logger() )->log('warning', 'manual_review_action_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            $notice = array('type' => 'error', 'message' => __('The review could not be recorded. See the PayBridge logs.', 'paybridge-for-plaid'));
        }
        $this->finish($order, $notice);
    }

    private function authorize(string $action): \WC_Order
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage payments.', 'paybridge-for-plaid'), '', array('response' => 403));
        }
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        check_admin_referer($action . '_' . $order_id);
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            wp_die(esc_html__('The order is not a PayBridge order.', 'paybridge-for-plaid'), '', array('response' => 404));
        }
        return $order;
    }

    /** @param array{type:string, message:string} $notice */
    private function finish(\WC_Order $order, array $notice): void
    {
        set_transient('pbfp_sync_notice_' . get_current_user_id() . '_' . $order->get_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }
}
