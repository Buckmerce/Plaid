<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Settings\Settings;

/**
 * Applies a PayBridge state transition to a WooCommerce order.
 *
 * The state machine decides; this class persists the new state first and then
 * projects it idempotently onto WooCommerce statuses, notes, alerts and hooks.
 * Projection is re-applied on duplicate evidence so a crash between the state
 * write and payment_complete() is repaired by the next replay.
 */
final class OrderPaymentProjector
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
        private readonly PaymentAlerts $alerts
    ) {
    }

    /**
     * @param array{source:string, transfer_id?:string, event_id?:string, failure_code?:string, return_code?:string, description?:string, reason?:string} $context
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
                $order->update_meta_data(OrderMeta::FAILURE_CODE, $this->code($context['failure_code']));
            }
            if (PaymentState::RETURNED === $to && '' !== ($context['return_code'] ?? '')) {
                $order->update_meta_data(OrderMeta::RETURN_CODE, $this->code($context['return_code']));
            }
            if (PaymentState::MANUAL_REVIEW === $to) {
                $order->update_meta_data(OrderMeta::MANUAL_REVIEW_REASON, $this->code($context['reason'] ?? 'manual_review'));
            }
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
            ));
        }
        if (in_array($decision, array(PaymentStateMachine::APPLY, PaymentStateMachine::NOOP), true)) {
            $this->project($order, $to, $context, PaymentStateMachine::APPLY === $decision);
            if (PaymentStateMachine::APPLY === $decision) {
                do_action('paybridge_plaid_payment_state_changed', $order, '' === $from ? 'new' : $from, $to);
            }
        }
        return $decision;
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
                    $order->update_status('on-hold', __('Bank payment submitted; awaiting ACH settlement.', 'paybridge-for-plaid'));
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
                    do_action('paybridge_plaid_payment_failed', $order, $context['failure_code'] ?? '');
                }
                break;
            case PaymentState::CANCELLED:
                if (! $order->is_paid() && ! $order->has_status(array('failed', 'cancelled', 'refunded'))) {
                    $order->update_status('cancelled');
                }
                if ($changed) {
                    do_action('paybridge_plaid_payment_cancelled', $order);
                }
                break;
            case PaymentState::RETURNED:
                if (! $order->has_status(array('failed', 'refunded'))) {
                    // A returned ACH debit reversed the funds: the order is no longer paid.
                    $order->update_status('failed', __('ACH return received: the bank payment was reversed.', 'paybridge-for-plaid'));
                }
                if ('yes' !== $order->get_meta('_pbfp_return_alerted', true)) {
                    $code = (string) $order->get_meta(OrderMeta::RETURN_CODE, true);
                    $this->alerts->add($order, 'returned', $code);
                    $this->email_merchant($order, $code);
                    $order->update_meta_data('_pbfp_return_alerted', 'yes');
                    $order->save();
                    do_action('paybridge_plaid_payment_returned', $order, $code);
                }
                break;
            case PaymentState::MANUAL_REVIEW:
                if ($order->has_status(array('pending', 'failed'))) {
                    $order->update_status('on-hold');
                }
                if ($changed) {
                    $this->alerts->add($order, 'manual_review', (string) $order->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true));
                    do_action('paybridge_plaid_payment_manual_review', $order, (string) $order->get_meta(OrderMeta::MANUAL_REVIEW_REASON, true));
                }
                break;
        }
    }

    private function confirm(\WC_Order $order, string $transfer_id): void
    {
        if ($order->is_paid() || $order->has_status(array('cancelled', 'refunded'))) {
            return;
        }
        // WooCommerce decides processing vs completed and handles stock/emails.
        $order->payment_complete($transfer_id);
        do_action('paybridge_plaid_payment_confirmed', $order, $transfer_id);
    }

    private function email_merchant(\WC_Order $order, string $code): void
    {
        $recipient = (string) get_option('admin_email');
        if ('' === $recipient || ! is_email($recipient)) {
            return;
        }
        $subject = sprintf(
            /* translators: 1: site name, 2: order number */
            __('[%1$s] ACH return received for order #%2$s', 'paybridge-for-plaid'),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number()
        );
        $body = sprintf(
            /* translators: 1: order number, 2: ACH return code, 3: order admin URL */
            __("The bank payment for order #%1\$s was returned (%2\$s). The funds were reversed.\n\nReview the order: %3\$s", 'paybridge-for-plaid'),
            $order->get_order_number(),
            '' === $code ? __('no return code', 'paybridge-for-plaid') : $code,
            $order->get_edit_order_url()
        );
        wp_mail($recipient, $subject, $body);
    }

    /** @param array<string, string> $context */
    private function note(string $state, array $context): string
    {
        $transfer = '' !== ($context['transfer_id'] ?? '') ? ' ' . sprintf(/* translators: %s: Plaid transfer ID */ __('Transfer ID: %s.', 'paybridge-for-plaid'), $context['transfer_id']) : '';
        $code = $this->code($context['return_code'] ?? ($context['failure_code'] ?? ''));
        $reason = '' !== $code ? ' (' . $code . ')' : '';
        switch ($state) {
            case PaymentState::INTENT_CREATED:
                return __('PayBridge: Plaid Transfer Intent created. Waiting for the customer to authorize the bank payment.', 'paybridge-for-plaid');
            case PaymentState::INTENT_PENDING:
                return __('PayBridge: bank payment authorization in progress.', 'paybridge-for-plaid');
            case PaymentState::INTENT_FAILED:
                return __('PayBridge: bank payment authorization failed or was declined', 'paybridge-for-plaid') . $reason . '. ' . __('The customer can retry.', 'paybridge-for-plaid');
            case PaymentState::INTENT_UNCERTAIN:
                return __('PayBridge: the Transfer Intent request had an unknown outcome. No Link token was issued for it, so it cannot move money; a retry creates a new intent.', 'paybridge-for-plaid');
            case PaymentState::TRANSFER_CREATED:
                return __('PayBridge: Plaid transfer created.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::PENDING:
                return __('PayBridge: transfer pending.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::POSTED:
                return __('PayBridge: transfer posted to the ACH network.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::SETTLED:
                return __('PayBridge: transfer settled.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::FUNDS_AVAILABLE:
                return __('PayBridge: funds available.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::FAILED:
                return __('PayBridge: transfer failed; no funds were moved', 'paybridge-for-plaid') . $reason . '.' . $transfer;
            case PaymentState::CANCELLED:
                return __('PayBridge: transfer cancelled.', 'paybridge-for-plaid') . $transfer;
            case PaymentState::RETURNED:
                return __('PayBridge: ACH RETURN — the bank payment was returned and the funds reversed', 'paybridge-for-plaid') . $reason . '.' . $transfer;
            case PaymentState::MANUAL_REVIEW:
                return __('PayBridge: payment requires manual review', 'paybridge-for-plaid') . ' (' . $this->code($context['reason'] ?? 'manual_review') . ').' . $transfer;
        }
        return sprintf(/* translators: %s: payment state */ __('PayBridge: payment state changed to %s.', 'paybridge-for-plaid'), $state);
    }

    private function code(string $code): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '', 0, 64);
    }
}
