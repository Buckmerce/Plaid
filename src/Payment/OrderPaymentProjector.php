<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Applies a Buckmerce state transition to a WooCommerce order.
 *
 * The state machine decides; this class persists the new state first and then
 * projects it idempotently onto WooCommerce statuses, notes, alerts and hooks.
 * Projection is re-applied on duplicate evidence so a crash between the state
 * write and payment_complete() is repaired by the next replay.
 *
 * Returned payments keep their history (ADR-0012): the WooCommerce status becomes
 * "failed" (unpaid, excluded from revenue, payable again by the customer), while the paid
 * date, transaction ID, transfer ID, notes and Plaid timestamps stay on the order.
 */
final class OrderPaymentProjector
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
        private readonly PaymentAlerts $alerts,
        private readonly ?PaymentMonitor $monitor = null,
        private readonly ?ReturnListener $return_listener = null,
        private readonly MerchantNotifier $notifier = new MerchantNotifier()
    ) {
    }

    /**
     * @param array{source:string, transfer_id?:string, event_id?:string, failure_code?:string, return_code?:string, description?:string, reason?:string, occurred_at?:string} $context
     * @return string PaymentStateMachine decision.
     */
    public function transition(\WC_Order $order, string $to, array $context): string
    {
        $from = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (isset(PaymentState::LIFECYCLE_RANK[$to]) && $order->has_status('cancelled') && ! PaymentState::is_terminal($from) && PaymentState::MANUAL_REVIEW !== $from) {
            // Money is moving for an order WooCommerce already cancelled: never
            // ignore it and never fulfil it automatically.
            $to = PaymentState::MANUAL_REVIEW;
            $context['reason'] = 'payment_for_cancelled_order';
        }
        $decision = PaymentStateMachine::decide($from, $to);
        if (PaymentStateMachine::CONFLICT === $decision) {
            $this->logger->log('warning', 'payment_state_conflict', array(
                'order_id' => $order->get_id(),
                'from' => '' === $from ? 'new' : $from,
                'to' => $to,
                'source' => $context['source'],
                'transfer_id' => $context['transfer_id'] ?? '',
                'event_id' => $context['event_id'] ?? '',
            ));
            return $decision;
        }
        if (PaymentStateMachine::APPLY === $decision) {
            $order->update_meta_data(OrderMeta::PAYMENT_STATE, $to);
            if ('' !== ($context['failure_code'] ?? '')) {
                $order->update_meta_data(OrderMeta::FAILURE_CODE, self::code($context['failure_code']));
            }
            if (PaymentState::RETURNED === $to && '' !== ($context['return_code'] ?? '')) {
                $order->update_meta_data(OrderMeta::RETURN_CODE, self::code($context['return_code']));
            }
            if (in_array($to, array(PaymentState::FAILED, PaymentState::RETURNED), true) && '' !== ($context['description'] ?? '')) {
                $order->update_meta_data(OrderMeta::FAILURE_DESCRIPTION, self::text($context['description']));
            }
            if (PaymentState::MANUAL_REVIEW === $to) {
                $order->update_meta_data(OrderMeta::MANUAL_REVIEW_REASON, self::code($context['reason'] ?? 'manual_review'));
            }
            $this->record_timestamp($order, $to, $context['occurred_at'] ?? '');
            // Persist and verify the new state before any WooCommerce side effect.
            OrderPersistence::save($order, array(OrderMeta::PAYMENT_STATE => $to));
            $order->add_order_note($this->note($to, $context));
            $this->logger->log('info', 'payment_state_changed', array(
                'order_id' => $order->get_id(),
                'from' => '' === $from ? 'new' : $from,
                'to' => $to,
                'source' => $context['source'],
                'transfer_id' => $context['transfer_id'] ?? '',
                'event_id' => $context['event_id'] ?? '',
                'environment' => (string) $order->get_meta(OrderMeta::ENVIRONMENT, true),
            ));
        }
        if (in_array($decision, array(PaymentStateMachine::APPLY, PaymentStateMachine::NOOP), true)) {
            $this->project($order, $to, $context, PaymentStateMachine::APPLY === $decision);
            if (PaymentStateMachine::APPLY === $decision) {
                $this->monitor?->refresh($order);
                do_action('buckmerce_plaid_payment_state_changed', $order, '' === $from ? 'new' : $from, $to);
            }
        }
        return $decision;
    }

    /** First provider timestamp of a lifecycle milestone; later duplicates never overwrite it. */
    private function record_timestamp(\WC_Order $order, string $state, string $occurred_at): void
    {
        $key = array(
            PaymentState::SETTLED => OrderMeta::SETTLED_AT,
            PaymentState::FUNDS_AVAILABLE => OrderMeta::FUNDS_AVAILABLE_AT,
            PaymentState::RETURNED => OrderMeta::RETURNED_AT,
        )[$state] ?? '';
        if ('' === $key || '' !== (string) $order->get_meta($key, true)) {
            return;
        }
        $time = '' === $occurred_at ? false : strtotime($occurred_at);
        $order->update_meta_data($key, gmdate('c', false === $time ? time() : $time));
        if (PaymentState::FUNDS_AVAILABLE === $state && '' === (string) $order->get_meta(OrderMeta::SETTLED_AT, true)) {
            // Funds are only available after settlement; the return windows count from it.
            $order->update_meta_data(OrderMeta::SETTLED_AT, gmdate('c', false === $time ? time() : $time));
        }
    }

    /** @param array<string, string> $context */
    private function project(\WC_Order $order, string $state, array $context, bool $changed): void
    {
        $transfer_id = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
        switch ($state) {
            case PaymentState::TRANSFER_CREATED:
            case PaymentState::PENDING:
            case PaymentState::POSTED:
                if ($order->has_status(array('pending', 'failed'))) {
                    $order->update_status('on-hold', __('Bank payment submitted; awaiting ACH settlement.', 'buckmerce-for-plaid'));
                }
                break;
            case PaymentState::SETTLED:
                if ('settled' === $this->settings->confirmation_state()) {
                    $this->confirm($order, $transfer_id);
                } elseif ($order->has_status(array('pending', 'failed'))) {
                    $order->update_status('on-hold');
                }
                break;
            case PaymentState::FUNDS_AVAILABLE:
                $this->confirm($order, $transfer_id);
                break;
            case PaymentState::FAILED:
                if (! $order->is_paid() && ! $order->has_status(array('failed', 'cancelled', 'refunded'))) {
                    $order->update_status('failed');
                }
                if ($changed) {
                    do_action('buckmerce_plaid_payment_failed', $order, $context['failure_code'] ?? '');
                }
                break;
            case PaymentState::CANCELLED:
                if (! $order->is_paid() && ! $order->has_status(array('failed', 'cancelled', 'refunded'))) {
                    $order->update_status('cancelled');
                }
                if ($changed) {
                    do_action('buckmerce_plaid_payment_cancelled', $order);
                }
                break;
            case PaymentState::RETURNED:
                $this->project_return($order, $transfer_id);
                break;
            case PaymentState::MANUAL_REVIEW:
                if ($order->has_status(array('pending', 'failed'))) {
                    $order->update_status('on-hold');
                }
                if ($changed) {
                    $reason = (string) $order->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true);
                    $this->alerts->add($order, PaymentAlerts::MANUAL_REVIEW, $reason);
                    $this->notifier->send(
                        $order,
                        /* translators: %s: order number */
                        sprintf(__('Bank payment for order #%s needs review', 'buckmerce-for-plaid'), $order->get_order_number()),
                        sprintf(
                            /* translators: 1: order number, 2: reason code */
                            __("Buckmerce stopped automatic processing of the bank payment for order #%1\$s (%2\$s). No order was fulfilled automatically. Open the order to see the Plaid identifiers and decide how to proceed.", 'buckmerce-for-plaid'),
                            $order->get_order_number(),
                            '' === $reason ? 'manual_review' : $reason
                        )
                    );
                    do_action('buckmerce_plaid_payment_manual_review', $order, $reason);
                }
                break;
        }
    }

    /**
     * A returned ACH debit reversed the funds. The order stops being paid, but its history is
     * kept; the merchant is alerted once per attempt, with a stronger alert when refunds were
     * already issued for the same payment (the merchant may lose both).
     */
    private function project_return(\WC_Order $order, string $transfer_id): void
    {
        if (! $order->has_status(array('failed', 'refunded'))) {
            $order->update_status('failed', __('ACH return received: the bank payment was reversed. The original payment details are kept on this order.', 'buckmerce-for-plaid'));
        }
        if ('yes' === $order->get_meta(OrderMeta::RETURN_ALERTED, true)) {
            return;
        }
        $code = (string) $order->get_meta(OrderMeta::RETURN_CODE, true);
        $retry = ReturnRetryPolicy::for_order($order);
        if ($retry->is_blocked()) {
            // Plaid restricts reprocessing returned debits; Buckmerce never re-debits this order (ADR-0019).
            $order->add_order_note(__('Buckmerce: this order will not be debited again by bank.', 'buckmerce-for-plaid') . ' ' . ReturnRetryPolicy::merchant_explanation($retry));
        }
        $exposure = null === $this->return_listener ? array('count' => 0, 'amount' => '0.00') : $this->return_listener->on_payment_returned($order, $transfer_id);
        if ($exposure['count'] > 0) {
            $this->alerts->add($order, PaymentAlerts::RETURNED_AFTER_REFUND, $code, '', $exposure['amount']);
            $order->add_order_note(sprintf(
                /* translators: 1: refunded amount, 2: ACH return code */
                __('Buckmerce: CRITICAL — the bank payment was returned (%2$s) after refunds of $%1$s were issued. The customer may have received this money twice. Contact the customer before taking further action.', 'buckmerce-for-plaid'),
                $exposure['amount'],
                '' === $code ? '—' : $code
            ));
            $this->notifier->send(
                $order,
                /* translators: %s: order number */
                sprintf(__('URGENT: ACH return after refund for order #%s', 'buckmerce-for-plaid'), $order->get_order_number()),
                sprintf(
                    /* translators: 1: order number, 2: ACH return code, 3: refunded amount */
                    __("The bank payment for order #%1\$s was returned (%2\$s) after you refunded $%3\$s. The customer's bank reversed the payment, so the customer may have received this money twice and you may lose both the payment and the refund. Pending refunds were cancelled where Plaid still allowed it; check the order notes.", 'buckmerce-for-plaid'),
                    $order->get_order_number(),
                    '' === $code ? __('no return code', 'buckmerce-for-plaid') : $code,
                    $exposure['amount']
                )
            );
        } else {
            $this->alerts->add($order, PaymentAlerts::RETURNED, $code);
            $this->notifier->send(
                $order,
                /* translators: %s: order number */
                sprintf(__('ACH return received for order #%s', 'buckmerce-for-plaid'), $order->get_order_number()),
                sprintf(
                    /* translators: 1: order number, 2: ACH return code */
                    __('The bank payment for order #%1$s was returned (%2$s). The funds were reversed. The order is now Failed; its original payment details are kept.', 'buckmerce-for-plaid'),
                    $order->get_order_number(),
                    '' === $code ? __('no return code', 'buckmerce-for-plaid') : $code
                ) . ' ' . ReturnRetryPolicy::merchant_explanation($retry)
            );
        }
        $order->update_meta_data(OrderMeta::RETURN_ALERTED, 'yes');
        $order->save();
        do_action('buckmerce_plaid_payment_returned', $order, $code);
    }

    private function confirm(\WC_Order $order, string $transfer_id): void
    {
        if ($order->is_paid() || $order->has_status(array('cancelled', 'refunded'))) {
            return;
        }
        if (null !== $order->get_date_paid('edit') && AttemptHistory::has_returned_attempt($order)) {
            // Re-payment after a return: the order is paid by this attempt. The earlier paid
            // date stays in the attempt history (ADR-0012), not on the order.
            $order->set_date_paid(time());
        }
        // WooCommerce decides processing vs completed and handles stock/emails.
        $order->payment_complete($transfer_id);
        do_action('buckmerce_plaid_payment_confirmed', $order, $transfer_id);
    }

    /** @param array<string, string> $context */
    private function note(string $state, array $context): string
    {
        $transfer = '' !== ($context['transfer_id'] ?? '') ? ' ' . sprintf(/* translators: %s: Plaid transfer ID */ __('Transfer ID: %s.', 'buckmerce-for-plaid'), $context['transfer_id']) : '';
        $code = self::code($context['return_code'] ?? ($context['failure_code'] ?? ''));
        $reason = '' !== $code ? ' (' . $code . ')' : '';
        $description = '' !== ($context['description'] ?? '') ? ' ' . self::text($context['description']) : '';
        switch ($state) {
            case PaymentState::INTENT_CREATED:
                return __('Buckmerce: Plaid Transfer Intent created. Waiting for the customer to authorize the bank payment.', 'buckmerce-for-plaid');
            case PaymentState::INTENT_PENDING:
                return __('Buckmerce: bank payment authorization in progress.', 'buckmerce-for-plaid');
            case PaymentState::INTENT_FAILED:
                return __('Buckmerce: bank payment authorization failed or was declined', 'buckmerce-for-plaid') . $reason . '. ' . __('The customer can retry.', 'buckmerce-for-plaid');
            case PaymentState::INTENT_UNCERTAIN:
                return __('Buckmerce: the Transfer Intent request had an unknown outcome. No Link token was issued for it, so it cannot move money; a retry creates a new intent.', 'buckmerce-for-plaid');
            case PaymentState::TRANSFER_CREATED:
                return __('Buckmerce: Plaid transfer created.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::PENDING:
                return __('Buckmerce: transfer pending.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::POSTED:
                return __('Buckmerce: transfer posted to the ACH network.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::SETTLED:
                return __('Buckmerce: transfer settled.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::FUNDS_AVAILABLE:
                return __('Buckmerce: funds available. The customer\'s bank can still return the payment within the ACH return windows; Buckmerce keeps monitoring it.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::FAILED:
                return __('Buckmerce: transfer failed; no funds were moved', 'buckmerce-for-plaid') . $reason . '.' . $description . $transfer;
            case PaymentState::CANCELLED:
                return __('Buckmerce: transfer cancelled.', 'buckmerce-for-plaid') . $transfer;
            case PaymentState::RETURNED:
                return __('Buckmerce: ACH RETURN — the bank payment was returned and the funds reversed', 'buckmerce-for-plaid') . $reason . '.' . $description . $transfer;
            case PaymentState::MANUAL_REVIEW:
                return __('Buckmerce: payment requires manual review', 'buckmerce-for-plaid') . ' (' . self::code($context['reason'] ?? 'manual_review') . ').' . $transfer;
        }
        return sprintf(/* translators: %s: payment state */ __('Buckmerce: payment state changed to %s.', 'buckmerce-for-plaid'), $state);
    }

    public static function code(string $code): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '', 0, 64);
    }

    /** Provider free text reduced to a short, markup-free sentence. */
    public static function text(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        return trim(mb_substr(trim($text), 0, 200));
    }
}
