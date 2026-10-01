<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

/**
 * Emails the store administrator about payment events that need a human: ACH returns,
 * refund failures and manual review. Contains order numbers, amounts and Plaid codes only
 * — never credentials or customer bank data.
 */
final class MerchantNotifier
{
    public function send(\WC_Order $order, string $subject, string $body): void
    {
        $recipient = (string) get_option('admin_email');
        /**
         * Recipients of Buckmerce payment alerts (defaults to the site administrator email).
         *
         * @param string    $recipient Comma-separated email addresses.
         * @param \WC_Order $order     The affected order.
         */
        $recipient = (string) apply_filters('buckmerce_plaid_alert_recipient', $recipient, $order);
        $valid = array_values(array_filter(array_map('trim', explode(',', $recipient)), static fn (string $email): bool => false !== is_email($email)));
        if (array() === $valid) {
            return;
        }
        $subject = sprintf(
            /* translators: 1: site name, 2: alert subject */
            __('[%1$s] %2$s', 'buckmerce-plaid'),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            $subject
        );
        $body .= "\n\n" . sprintf(
            /* translators: %s: order admin URL */
            __('Review the order: %s', 'buckmerce-plaid'),
            $order->get_edit_order_url()
        );
        wp_mail($valid, $subject, $body);
    }
}
