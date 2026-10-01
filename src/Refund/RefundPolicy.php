<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

use PayBridge\Plaid\Exception\PaymentException;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentSnapshot;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Settings\Settings;
use PayBridge\Plaid\Support\Money;

/**
 * Decides whether, and how much, a PayBridge payment can be refunded (ADR-0016).
 * Uses only local, authoritative-derived data: order meta written from Plaid responses
 * and the refund store. It never calls Plaid, so it can run on every admin page view.
 *
 * Plaid rules (https://plaid.com/docs/transfer/refunds/): refunds of a debit transfer only, at most
 * 10 per transfer, their total at most the transfer amount, within 180 days of the
 * transfer, never for cancelled/failed/returned transfers. PayBridge additionally refunds
 * only settled payments: before settlement the debit is still likely to fail or return,
 * and cancelling the transfer is the right tool.
 */
final class RefundPolicy
{
    public const MAX_REFUNDS_PER_TRANSFER = 10;
    public const REFUND_WINDOW_DAYS = 180;
    public const REFUNDABLE_TRANSFER_STATUSES = array('settled', 'funds_available');

    /** @param list<RefundRecord> $records Refunds of this order (any transfer). */
    public static function evaluate(\WC_Order $order, Settings $settings, array $records, ?int $now = null): RefundEligibility
    {
        $now ??= time();
        if (Settings::GATEWAY_ID !== $order->get_payment_method()) {
            return RefundEligibility::denied('not_paybridge', __('This order was not paid with Pay by Bank.', 'paybridge-for-plaid'));
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        $transfer_id = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
        if (null === $snapshot || '' === $transfer_id) {
            return RefundEligibility::denied('no_transfer', __('There is no Plaid bank payment to refund for this order.', 'paybridge-for-plaid'));
        }
        $transfer_records = array_values(array_filter($records, static fn (RefundRecord $record): bool => $record->transfer_id === $transfer_id));
        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (in_array($state, array(PaymentState::FAILED, PaymentState::CANCELLED, PaymentState::RETURNED), true)) {
            return RefundEligibility::denied('payment_not_refundable', __('The bank payment failed, was cancelled or was returned, so there is nothing to refund.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        $transfer_status = (string) $order->get_meta(OrderMeta::TRANSFER_STATUS, true);
        if (! in_array($transfer_status, self::REFUNDABLE_TRANSFER_STATUSES, true)) {
            return RefundEligibility::denied('not_settled', __('The bank payment has not settled yet. Refund it after it settles; until then it may still fail or be returned.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        if ($snapshot->environment !== $settings->environment_name()) {
            return RefundEligibility::denied('environment_mismatch', __('This payment was made in another Plaid environment than the one configured now.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        $order_account = (string) $order->get_meta(OrderMeta::ACCOUNT_FINGERPRINT, true);
        if ('' !== $order_account && $order_account !== $settings->account_fingerprint()) {
            return RefundEligibility::denied('account_mismatch', __('This payment belongs to a different Plaid account than the one configured now.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        $created = strtotime((string) $order->get_meta(OrderMeta::TRANSFER_CREATED_AT, true));
        $created = false === $created ? strtotime($snapshot->created_at) : $created;
        if (false !== $created && $created < $now - self::REFUND_WINDOW_DAYS * DAY_IN_SECONDS) {
            return RefundEligibility::denied('refund_window_expired', __('Plaid refunds are possible only within 180 days of the payment.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        foreach ($transfer_records as $record) {
            if (RefundState::UNCERTAIN === $record->status || (RefundState::CREATING === $record->status && $record->lease_is_live($now))) {
                return RefundEligibility::denied('refund_unconfirmed', __('A previous refund for this order is still being confirmed with Plaid. Wait until it is confirmed before refunding again.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
            }
        }
        $counted = array_filter($transfer_records, static fn (RefundRecord $record): bool => in_array($record->status, RefundState::COUNTED, true));
        if (count($counted) >= self::MAX_REFUNDS_PER_TRANSFER) {
            return RefundEligibility::denied('refund_limit', __('Plaid allows at most 10 refunds per payment.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        try {
            $refunded = Money::sum(array_values(array_map(
                static fn (RefundRecord $record): string => $record->amount,
                array_filter($transfer_records, static fn (RefundRecord $record): bool => RefundState::is_active($record->status))
            )));
            $remaining = Money::remaining($snapshot->amount, $refunded);
        } catch (PaymentException) {
            return RefundEligibility::denied('invalid_amounts', __('The recorded refund amounts are invalid; review the order.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records);
        }
        if (0 === Money::to_cents($remaining)) {
            return RefundEligibility::denied('fully_refunded', __('The bank payment has already been refunded in full.', 'paybridge-for-plaid'), $transfer_id, $snapshot, $transfer_records, $refunded);
        }
        return RefundEligibility::allowed($transfer_id, $snapshot, $transfer_records, $refunded, $remaining);
    }
}
