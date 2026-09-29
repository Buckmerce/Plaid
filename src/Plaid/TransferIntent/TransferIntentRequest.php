<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\TransferIntent;

use PayBridge\Plaid\Payment\PaymentSnapshot;

/**
 * Builds the /transfer/intent/create request exclusively from server-side
 * data: the immutable snapshot, the WooCommerce order and merchant settings.
 */
final class TransferIntentRequest
{
    /**
     * @param array{legal_name:string, email_address?:string, phone_number?:string} $user
     * @return array<string, mixed>
     */
    public static function build(PaymentSnapshot $snapshot, string $order_number, array $user, string $network, string $ach_class, string $funding_account_id, string $site_marker = ''): array
    {
        $body = array(
            'mode' => 'PAYMENT',
            'amount' => $snapshot->amount,
            'iso_currency_code' => $snapshot->currency,
            'description' => self::description($order_number),
            'network' => $network,
            'ach_class' => $ach_class,
            'user' => $user,
            // Correlation only: ASCII strings, no secrets and no personal data.
            'metadata' => array(
                'pbfp_order_id' => (string) $snapshot->order_id,
                'pbfp_attempt_id' => $snapshot->attempt_id,
                'pbfp_environment' => $snapshot->environment,
            ),
        );
        if ('' !== $site_marker) {
            $body['metadata']['pbfp_site'] = $site_marker;
        }
        if ('' !== $funding_account_id) {
            // Only valid for accounts without Plaid Ledger (ADR-0007).
            $body['funding_account_id'] = $funding_account_id;
        }
        return $body;
    }

    /** Plaid requires 1–15 characters. Keep it ASCII so it is valid on bank statements. */
    public static function description(string $order_number): string
    {
        $number = preg_replace('/[^A-Za-z0-9\-]/', '', $order_number) ?? '';
        $description = '' === $number ? 'Order payment' : 'Order ' . $number;
        return substr($description, 0, 15);
    }

    /**
     * Legal name and contact details from the order billing data.
     *
     * @return array{legal_name:string, email_address?:string}
     */
    public static function user_from_order(\WC_Order $order): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?? '');
        if ('' === $name) {
            $name = trim((string) $order->get_billing_company());
        }
        $user = array('legal_name' => '' === $name ? 'Customer' : substr($name, 0, 100));
        $email = (string) $order->get_billing_email();
        if ('' !== $email && is_email($email)) {
            $user['email_address'] = $email;
        }
        // phone_number is intentionally omitted: Plaid validates it against real
        // numbering plans and malformed store data would block the payment.
        return $user;
    }
}
