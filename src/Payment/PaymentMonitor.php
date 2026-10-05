<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Persistence\PaymentLockStore;

/**
 * Projects an order's current payment attempt into the payment index: its state, when
 * Plaid must be re-read and until when it is monitored (MonitoringPolicy).
 * Idempotent; called after every authoritative change and by reconciliation.
 */
final class PaymentMonitor
{
    public function __construct(private readonly PaymentLockStore $locks)
    {
    }

    public function refresh(\WC_Order $order, ?int $now = null): void
    {
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null === $snapshot) {
            return;
        }
        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        $plan = MonitoringPolicy::plan(self::input($order, $state, $snapshot, $now ?? time()));
        $this->locks->record_monitoring($order->get_id(), $snapshot->attempt_id, $state, $plan['next'], $plan['until']);
    }

    /** @return array{state:string, now:int, attempt_created_at:int|null, link_window_ends_at:int|null, transfer_created_at:int|null, settled_at:int|null, standard_return_window:string, unauthorized_return_window:string, has_transfer:bool} */
    public static function input(\WC_Order $order, string $state, PaymentSnapshot $snapshot, int $now): array
    {
        return array(
            'state' => $state,
            'now' => $now,
            'attempt_created_at' => self::time($snapshot->created_at),
            'link_window_ends_at' => self::time((string) $order->get_meta(OrderMeta::LINK_TOKEN_EXPIRES_AT, true)),
            'transfer_created_at' => self::time((string) $order->get_meta(OrderMeta::TRANSFER_CREATED_AT, true)),
            'settled_at' => self::time((string) $order->get_meta(OrderMeta::SETTLED_AT, true)),
            'standard_return_window' => (string) $order->get_meta(OrderMeta::STANDARD_RETURN_WINDOW, true),
            'unauthorized_return_window' => (string) $order->get_meta(OrderMeta::UNAUTHORIZED_RETURN_WINDOW, true),
            'has_transfer' => '' !== (string) $order->get_meta(OrderMeta::TRANSFER_ID, true),
        );
    }

    private static function time(string $value): ?int
    {
        if ('' === $value) {
            return null;
        }
        $timestamp = strtotime($value);
        return false === $timestamp ? null : $timestamp;
    }
}
