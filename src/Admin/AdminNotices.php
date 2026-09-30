<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Payment\PaymentAlerts;

/** Persistent admin notices for ACH returns, refund problems and payments needing review. */
final class AdminNotices
{
    public const DISMISS_ACTION = 'pbfp_dismiss_alert';

    public function __construct(private readonly PaymentAlerts $alerts = new PaymentAlerts())
    {
    }

    public function register(): void
    {
        add_action('admin_notices', array($this, 'render'));
        add_action('admin_post_' . self::DISMISS_ACTION, array($this, 'dismiss'));
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        foreach ($this->alerts->all() as $key => $alert) {
            $order = wc_get_order($alert['order_id']);
            $link = $order instanceof \WC_Order ? $order->get_edit_order_url() : '';
            $dismiss = wp_nonce_url(add_query_arg(array('action' => self::DISMISS_ACTION, 'alert' => rawurlencode($key)), admin_url('admin-post.php')), self::DISMISS_ACTION);
            $critical = in_array($alert['type'], PaymentAlerts::CRITICAL, true);
            echo '<div class="notice ' . esc_attr($critical ? 'notice-error' : 'notice-warning') . ' pbfp-alert"><p><strong>' . esc_html(PaymentAlerts::message($alert)) . '</strong> ';
            if ('' !== $link) {
                echo '<a href="' . esc_url($link) . '">' . esc_html__('Review order', 'paybridge-for-plaid') . '</a> · ';
            }
            echo '<a href="' . esc_url($dismiss) . '">' . esc_html__('Dismiss', 'paybridge-for-plaid') . '</a></p></div>';
        }
    }

    public function dismiss(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'paybridge-for-plaid'), '', array('response' => 403));
        }
        check_admin_referer(self::DISMISS_ACTION);
        $key = isset($_GET['alert']) ? sanitize_text_field(wp_unslash($_GET['alert'])) : '';
        if ('' !== $key) {
            $this->alerts->dismiss($key);
        }
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }
}
