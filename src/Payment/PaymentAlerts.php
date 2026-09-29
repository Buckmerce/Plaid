<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/**
 * Persistent merchant alerts for payment problems that must not be overlooked
 * (ACH returns, money received for a cancelled order, manual review).
 */
final class PaymentAlerts
{
    public const OPTION = 'paybridge_plaid_payment_alerts';
    private const MAX_ALERTS = 100;

    public function add(\WC_Order $order, string $type, string $code = ''): void
    {
        $alerts = $this->all();
        $alerts[$order->get_id() . ':' . $type] = array(
            'order_id' => $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'type' => $type,
            'code' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '', 0, 32),
            'at' => gmdate('c'),
        );
        if (count($alerts) > self::MAX_ALERTS) {
            $alerts = array_slice($alerts, -self::MAX_ALERTS, null, true);
        }
        update_option(self::OPTION, $alerts, false);
    }

    /** @return array<string, array{order_id:int, order_number:string, type:string, code:string, at:string}> */
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
                    'at' => (string) ($alert['at'] ?? ''),
                );
            }
        }
        return $valid;
    }

    public function dismiss(string $key): void
    {
        $alerts = $this->all();
        unset($alerts[$key]);
        update_option(self::OPTION, $alerts, false);
    }
}
