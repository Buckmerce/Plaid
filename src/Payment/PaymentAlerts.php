<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/**
 * Persistent merchant alerts for payment problems that must not be overlooked
 * (ACH returns, returns after a refund, refunds that failed or were returned,
 * money received for a cancelled order, manual review). They stay until an
 * administrator dismisses them.
 */
final class PaymentAlerts
{
    public const OPTION = 'paybridge_plaid_payment_alerts';
    private const MAX_ALERTS = 200;

    public const RETURNED = 'returned';
    public const RETURNED_AFTER_REFUND = 'returned_after_refund';
    public const MANUAL_REVIEW = 'manual_review';
    public const REFUND_FAILED = 'refund_failed';
    public const REFUND_RETURNED = 'refund_returned';
    public const REFUND_UNCERTAIN = 'refund_uncertain';
    public const REFUND_CANCELLED = 'refund_cancelled';
    public const EXTERNAL_REFUND = 'external_refund';

    /** Alerts that mean money was lost or may be lost. */
    public const CRITICAL = array(self::RETURNED, self::RETURNED_AFTER_REFUND, self::REFUND_FAILED, self::REFUND_RETURNED, self::REFUND_UNCERTAIN);

    public function add(\WC_Order $order, string $type, string $code = '', string $key_suffix = '', string $amount = ''): void
    {
        $alerts = $this->all();
        $key = $order->get_id() . ':' . $type . ('' === $key_suffix ? '' : ':' . preg_replace('/[^A-Za-z0-9_\-]/', '', $key_suffix));
        $alerts[$key] = array(
            'order_id' => $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'type' => $type,
            'code' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '', 0, 32),
            'amount' => preg_match('/^[0-9]{1,12}\.[0-9]{2}$/', $amount) ? $amount : '',
            'at' => gmdate('c'),
        );
        if (count($alerts) > self::MAX_ALERTS) {
            $alerts = array_slice($alerts, -self::MAX_ALERTS, null, true);
        }
        update_option(self::OPTION, $alerts, false);
    }

    /** @return array<string, array{order_id:int, order_number:string, type:string, code:string, amount:string, at:string}> */
    public function all(): array
    {
        $alerts = get_option(self::OPTION, array());
        if (! is_array($alerts)) {
            return array();
        }
        $valid = array();
        foreach ($alerts as $key => $alert) {
            if (is_string($key) && is_array($alert) && isset($alert['order_id'], $alert['type'])) {
                $valid[$key] = array(
                    'order_id' => (int) $alert['order_id'],
                    'order_number' => (string) ($alert['order_number'] ?? $alert['order_id']),
                    'type' => (string) $alert['type'],
                    'code' => (string) ($alert['code'] ?? ''),
                    'amount' => (string) ($alert['amount'] ?? ''),
                    'at' => (string) ($alert['at'] ?? ''),
                );
            }
        }
        return $valid;
    }

    /** @return array<string, array{order_id:int, order_number:string, type:string, code:string, amount:string, at:string}> */
    public function for_order(int $order_id): array
    {
        return array_filter($this->all(), static fn (array $alert): bool => $order_id === $alert['order_id']);
    }

    public function dismiss(string $key): void
    {
        $alerts = $this->all();
        unset($alerts[$key]);
        update_option(self::OPTION, $alerts, false);
    }

    /** @param array<string, string|int> $alert */
    public static function message(array $alert): string
    {
        $number = (string) ($alert['order_number'] ?? '');
        $code = '' === (string) ($alert['code'] ?? '') ? '—' : (string) $alert['code'];
        $amount = '' === (string) ($alert['amount'] ?? '') ? '' : '$' . (string) $alert['amount'];
        return match ((string) ($alert['type'] ?? '')) {
            /* translators: 1: order number, 2: ACH return code */
            self::RETURNED => sprintf(__('PayBridge: the bank payment for order #%1$s was RETURNED (%2$s). The funds were reversed.', 'paybridge-for-plaid'), $number, $code),
            /* translators: 1: order number, 2: ACH return code */
            self::RETURNED_AFTER_REFUND => sprintf(__('PayBridge: the bank payment for order #%1$s was RETURNED (%2$s) AFTER a refund was issued. The customer may have received the money twice; you may lose both the payment and the refund. Review the order now.', 'paybridge-for-plaid'), $number, $code),
            /* translators: 1: refund amount, 2: order number, 3: failure code */
            self::REFUND_FAILED => sprintf(__('PayBridge: the refund of %1$s for order #%2$s FAILED at Plaid (%3$s). The customer did not receive it. Delete the WooCommerce refund record and refund again if needed.', 'paybridge-for-plaid'), $amount, $number, $code),
            /* translators: 1: refund amount, 2: order number, 3: ACH return code */
            self::REFUND_RETURNED => sprintf(__('PayBridge: the refund of %1$s for order #%2$s was RETURNED by the customer\'s bank (%3$s). The customer did not receive it; contact the customer.', 'paybridge-for-plaid'), $amount, $number, $code),
            /* translators: 1: refund amount, 2: order number */
            self::REFUND_UNCERTAIN => sprintf(__('PayBridge: Plaid did not confirm the refund of %1$s for order #%2$s. Do not refund this order again: PayBridge is verifying the refund with Plaid.', 'paybridge-for-plaid'), $amount, $number),
            /* translators: 1: refund amount, 2: order number */
            self::REFUND_CANCELLED => sprintf(__('PayBridge: the refund of %1$s for order #%2$s was cancelled because the original bank payment was returned.', 'paybridge-for-plaid'), $amount, $number),
            /* translators: 1: refund amount, 2: order number */
            self::EXTERNAL_REFUND => sprintf(__('PayBridge: a refund of %1$s for order #%2$s was created outside WooCommerce (for example in the Plaid Dashboard). Record it in WooCommerce as a manual refund.', 'paybridge-for-plaid'), $amount, $number),
            /* translators: 1: order number, 2: reason code */
            default => sprintf(__('PayBridge: order #%1$s requires manual payment review (%2$s).', 'paybridge-for-plaid'), $number, $code),
        };
    }
}
