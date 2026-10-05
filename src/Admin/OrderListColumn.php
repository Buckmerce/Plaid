<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Admin;

use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentState;
use Buckmerce\Plaid\Settings\Settings;

/**
 * "Pay by Bank" column in the WooCommerce orders list (HPOS and legacy). Returned payments
 * keep the standard Failed status; this column makes them — and payments in manual
 * review or still in the ACH return window — impossible to overlook in the list.
 */
final class OrderListColumn
{
    public const COLUMN = 'bmfp_payment';

    public function register(): void
    {
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'columns'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_hpos'), 10, 2);
        add_filter('manage_edit-shop_order_columns', array($this, 'columns'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_legacy'), 10, 2);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $result = array();
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ('order_status' === $key) {
                $result[self::COLUMN] = __('Pay by Bank', 'buckmerce-plaid');
            }
        }
        if (! isset($result[self::COLUMN])) {
            $result[self::COLUMN] = __('Pay by Bank', 'buckmerce-plaid');
        }
        return $result;
    }

    /** @param mixed $order */
    public function render_hpos(string $column, $order): void
    {
        if (self::COLUMN === $column && $order instanceof \WC_Order) {
            $this->render($order);
        }
    }

    public function render_legacy(string $column, int $post_id): void
    {
        if (self::COLUMN !== $column) {
            return;
        }
        $order = wc_get_order($post_id);
        if ($order instanceof \WC_Order) {
            $this->render($order);
        }
    }

    private function render(\WC_Order $order): void
    {
        if (Settings::GATEWAY_ID !== $order->get_payment_method()) {
            return;
        }
        [$label, $tone] = self::badge((string) $order->get_meta(OrderMeta::PAYMENT_STATE, true), (string) $order->get_meta(OrderMeta::RETURN_CODE, true));
        if ('' === $label) {
            return;
        }
        echo '<mark class="order-status bmfp-list-badge bmfp-list-badge--' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span></mark>';
    }

    /** @return array{string, string} label and tone */
    public static function badge(string $state, string $return_code): array
    {
        return match ($state) {
            /* translators: %s: ACH return code */
            PaymentState::RETURNED => array('' === $return_code ? __('Bank payment returned', 'buckmerce-plaid') : sprintf(__('Bank payment returned (%s)', 'buckmerce-plaid'), $return_code), 'critical'),
            PaymentState::MANUAL_REVIEW => array(__('Needs review', 'buckmerce-plaid'), 'warning'),
            PaymentState::FAILED, PaymentState::INTENT_FAILED => array(__('Bank payment failed', 'buckmerce-plaid'), 'failed'),
            PaymentState::CANCELLED => array(__('Bank payment cancelled', 'buckmerce-plaid'), 'failed'),
            PaymentState::TRANSFER_CREATED, PaymentState::PENDING, PaymentState::POSTED => array(__('Awaiting ACH settlement', 'buckmerce-plaid'), 'pending'),
            PaymentState::SETTLED => array(__('Settled', 'buckmerce-plaid'), 'pending'),
            PaymentState::FUNDS_AVAILABLE => array(__('Funds available', 'buckmerce-plaid'), 'ok'),
            PaymentState::INTENT_CREATED, PaymentState::INTENT_PENDING, PaymentState::INTENT_CREATING, PaymentState::INTENT_UNCERTAIN => array(__('Awaiting authorization', 'buckmerce-plaid'), 'pending'),
            default => array('', ''),
        };
    }
}
