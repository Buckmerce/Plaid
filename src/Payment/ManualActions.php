<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Persistence\DatabaseMutex;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Plaid\DTO\TransferIntent;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentService;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Explicit merchant actions on a payment (docs/RUNBOOKS.md). Each runs under the order's
 * payment mutex, re-reads Plaid first and never guesses: when Plaid's answer does not allow
 * the action, nothing changes.
 */
final class ManualActions
{
    public const DECISIONS = array('fulfil', 'refunded', 'contacted_customer', 'other');

    public function __construct(
        private readonly Settings $settings,
        private readonly TransferService $transfers,
        private readonly TransferIntentService $intents,
        private readonly TransferBinder $binder,
        private readonly PaymentLockStore $locks,
        private readonly PaymentAlerts $alerts,
        private readonly Logger $logger
    ) {
    }

    /**
     * Cancels a transfer that Plaid still reports as cancellable (not yet sent to the ACH
     * network). WooCommerce is updated from Plaid's resulting status, never assumed.
     *
     * @return string cancelled | not_cancellable
     * @throws PaymentException
     */
    public function cancel_transfer(\WC_Order $order, int $user_id): string
    {
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function () use ($order, $user_id): string {
            $order = $this->current($order);
            $transfer_id = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
            $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
            if ('' !== $transfer_id && PaymentState::CANCELLED === $state) {
                // Idempotent: a repeated request reports the outcome that already happened.
                return 'cancelled';
            }
            if ('' === $transfer_id || ! in_array($state, array(PaymentState::TRANSFER_CREATED, PaymentState::PENDING, PaymentState::MANUAL_REVIEW), true)) {
                return 'not_cancellable';
            }
            $transfer = $this->transfers->get($transfer_id);
            if ($transfer->cancellable) {
                try {
                    $this->transfers->cancel($transfer_id);
                } catch (PlaidApiException $exception) {
                    if ($exception->is_transient()) {
                        throw $exception;
                    }
                    $this->logger->log('warning', 'transfer_cancel_refused', array('order_id' => $order->get_id(), 'transfer_id' => $transfer_id, 'error_code' => $exception->safe_code()));
                }
                $transfer = $this->transfers->get($transfer_id);
            }
            $this->binder->apply_transfer_status($order, $transfer, 'merchant_cancel');
            $cancelled = 'cancelled' === $transfer->status;
            $order->add_order_note(sprintf(
                /* translators: 1: Plaid transfer ID, 2: user ID, 3: Plaid transfer status */
                $cancelled ? __('Buckmerce: transfer %1$s cancelled at Plaid by user #%2$d before it was sent to the bank.', 'buckmerce-plaid') : __('Buckmerce: transfer %1$s could not be cancelled (user #%2$d); Plaid reports it as %3$s.', 'buckmerce-plaid'),
                $transfer_id,
                $user_id,
                $transfer->status
            ));
            $this->logger->log('info', 'transfer_cancel_requested', array('order_id' => $order->get_id(), 'transfer_id' => $transfer_id, 'result' => $transfer->status, 'user_id' => $user_id));
            return $cancelled ? 'cancelled' : 'not_cancellable';
        });
    }

    /**
     * Records the merchant's decision about a payment in manual review. The payment state stays
     * quarantined (nothing is fulfilled automatically); the decision is audited and the alert
     * cleared. For a review without a transfer, the unused attempt can be released so the customer
     * can pay again — only after Plaid confirms the intent did not create a transfer.
     *
     * @return string recorded | released | refused
     * @throws PaymentException
     */
    public function resolve_review(\WC_Order $order, string $decision, bool $allow_new_attempt, int $user_id): string
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new PaymentException('Unknown review decision.');
        }
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function () use ($order, $decision, $allow_new_attempt, $user_id): string {
            $order = $this->current($order);
            if (PaymentState::MANUAL_REVIEW !== (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true)) {
                return 'refused';
            }
            $result = 'recorded';
            if ($allow_new_attempt) {
                $intent_id = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
                if (! $this->can_release($order, $intent_id)) {
                    return 'refused';
                }
                // A stored intent is retired in the payment index; an intent that was never stored
                // (quarantined at creation) left a claimable reservation and needs no retirement.
                if ('' !== $intent_id && ! $this->locks->retire($order->get_id(), $intent_id)) {
                    throw new PaymentException('The payment attempt could not be released.');
                }
                AttemptHistory::archive($order, 'review_released');
                OrderPersistence::save($order, array(OrderMeta::PAYMENT_STATE => null, OrderMeta::TRANSFER_INTENT_ID => null));
                if (! $order->is_paid() && $order->has_status('on-hold')) {
                    // The review put the unpaid order on hold; the merchant now lets the customer pay it.
                    $order->update_status('pending', __('Buckmerce: order reopened for payment after manual review.', 'buckmerce-plaid'));
                }
                $result = 'released';
            }
            $order->update_meta_data(OrderMeta::MANUAL_REVIEW_RESOLUTION, (string) wp_json_encode(array('decision' => $decision, 'user_id' => $user_id, 'at' => gmdate('c'), 'released' => 'released' === $result)));
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: decision code, 2: user ID */
                'released' === $result ? __('Buckmerce: manual review resolved (%1$s) by user #%2$d. The unused payment attempt was released; the customer can pay again.', 'buckmerce-plaid') : __('Buckmerce: manual review resolved (%1$s) by user #%2$d. Automatic processing of this payment stays stopped.', 'buckmerce-plaid'),
                $decision,
                $user_id
            ));
            $this->alerts->dismiss($order->get_id() . ':' . PaymentAlerts::MANUAL_REVIEW);
            $this->logger->log('info', 'manual_review_resolved', array('order_id' => $order->get_id(), 'decision' => $decision, 'result' => $result, 'user_id' => $user_id));
            return $result;
        });
    }

    /**
     * A review without a transfer whose intent can no longer move money: either it was never
     * stored (so no Link token was ever issued for it), or Plaid confirms it is not captured and
     * no Link token issued for it is still usable.
     */
    private function can_release(\WC_Order $order, string $intent_id): bool
    {
        if ('' !== (string) $order->get_meta(OrderMeta::TRANSFER_ID, true) || PaymentAttemptService::authorization_window_open($order)) {
            return false;
        }
        if (ReturnRetryPolicy::for_order($order)->is_blocked()) {
            // An earlier transfer of this order was returned: no new bank debit (ADR-0019).
            return false;
        }
        if ('' === $intent_id) {
            return '' === (string) $order->get_meta(OrderMeta::LINK_TOKEN_EXPIRES_AT, true);
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        if (null === $snapshot || $snapshot->environment !== $this->settings->environment_name()) {
            return false;
        }
        $intent = $this->intents->get($intent_id);
        return TransferIntent::SUCCEEDED !== $intent->status && '' === $intent->transfer_id;
    }

    private function current(\WC_Order $order): \WC_Order
    {
        $fresh = wc_get_order($order->get_id());
        if (! $fresh instanceof \WC_Order || Settings::GATEWAY_ID !== $fresh->get_payment_method()) {
            throw new PaymentException('The order is not a Buckmerce order.');
        }
        return $fresh;
    }
}
