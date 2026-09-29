<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Persistence\PaymentLockStatus;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Settings\Settings;

/**
 * Finds the order of a Plaid identifier through PayBridge's own payment index
 * (the reservation table). This works identically with HPOS and legacy order
 * storage and never relies on WooCommerce order meta queries.
 */
final class OrderLocator
{
    public const MATCH_ACTIVE = 'active';
    public const MATCH_RETIRED = 'retired';

    public function __construct(private readonly PaymentLockStore $locks = new PaymentLockStore())
    {
    }

    /** @return array{order:\WC_Order, match:string}|null */
    public function by_transfer_id(string $transfer_id): ?array
    {
        return $this->resolve($this->locks->find_by_transfer_id($transfer_id), OrderMeta::TRANSFER_ID, $transfer_id);
    }

    /** @return array{order:\WC_Order, match:string}|null */
    public function by_intent_id(string $intent_id): ?array
    {
        return $this->resolve($this->locks->find_by_intent_id($intent_id), OrderMeta::TRANSFER_INTENT_ID, $intent_id);
    }

    public function paybridge_order(int $order_id): ?\WC_Order
    {
        $order = wc_get_order($order_id);
        return $order instanceof \WC_Order && Settings::GATEWAY_ID === $order->get_payment_method() ? $order : null;
    }

    /**
     * @param array{order_id:int, status:string}|null $row
     * @return array{order:\WC_Order, match:string}|null
     */
    private function resolve(?array $row, string $meta_key, string $value): ?array
    {
        if (null === $row) {
            return null;
        }
        $order = $this->paybridge_order($row['order_id']);
        if (null === $order) {
            return null;
        }
        if (PaymentLockStatus::RETIRED === $row['status']) {
            return array('order' => $order, 'match' => self::MATCH_RETIRED);
        }
        // Defense in depth: the index and the order must agree.
        if ($value !== (string) $order->get_meta($meta_key, true)) {
            return null;
        }
        return array('order' => $order, 'match' => self::MATCH_ACTIVE);
    }
}
