<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Persistence\PaymentLockStatus;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Finds the order of a Plaid identifier through Buckmerce's own payment index
 * (the reservation table). This works identically with HPOS and legacy order
 * storage and never relies on WooCommerce order meta queries. Lookups are limited to
 * the Plaid account whose event stream is being processed.
 */
final class OrderLocator
{
    public const MATCH_ACTIVE = 'active';
    public const MATCH_RETIRED = 'retired';

    public function __construct(private readonly PaymentLockStore $locks = new PaymentLockStore())
    {
    }

    /** @return array{order:\WC_Order, match:string}|null */
    public function by_transfer_id(string $transfer_id, AccountScope $scope): ?array
    {
        return $this->resolve($this->locks->find_by_transfer_id($transfer_id, $scope), OrderMeta::TRANSFER_ID, $transfer_id);
    }

    /** @return array{order:\WC_Order, match:string}|null */
    public function by_intent_id(string $intent_id, AccountScope $scope): ?array
    {
        return $this->resolve($this->locks->find_by_intent_id($intent_id, $scope), OrderMeta::TRANSFER_INTENT_ID, $intent_id);
    }

    /**
     * Whether the order's payment attempt was made with the given Plaid account. Orders written
     * before the account fingerprint existed carry none and are attributed to the configured
     * account; any other fingerprint belongs to another account's stream.
     */
    public static function order_in_scope(\WC_Order $order, AccountScope $scope): bool
    {
        if ($scope->environment !== (string) $order->get_meta(OrderMeta::ENVIRONMENT, true)) {
            return false;
        }
        $account = (string) $order->get_meta(OrderMeta::ACCOUNT_FINGERPRINT, true);
        return '' === $account || hash_equals($scope->account_fp, $account);
    }

    public function buckmerce_order(int $order_id): ?\WC_Order
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
        $order = $this->buckmerce_order($row['order_id']);
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
