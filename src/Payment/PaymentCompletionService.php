<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Persistence\DatabaseMutex;
use Buckmerce\Plaid\Plaid\DTO\TransferIntent;
use Buckmerce\Plaid\Plaid\Exception\PlaidException;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentService;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Handles the browser's "Link finished" signal. The browser only triggers the
 * check; the result comes exclusively from Plaid /transfer/intent/get for the
 * intent stored on the order (ADR-0002). Safe to call repeatedly.
 */
final class PaymentCompletionService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TransferIntentService $intents,
        private readonly TransferBinder $binder,
        private readonly OrderPaymentProjector $projector,
        private readonly Logger $logger
    ) {
    }

    /** @throws PaymentException */
    public function complete(\WC_Order $order): CompletionResult
    {
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function () use ($order): CompletionResult {
            $order = wc_get_order($order->get_id());
            if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
                throw new PaymentException('The order could not be loaded.');
            }
            $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
            if (PaymentState::has_transfer($state) && ! PaymentState::is_terminal($state)) {
                return new CompletionResult(CompletionResult::SUBMITTED);
            }
            $intent_id = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
            $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
            if ('' === $intent_id || null === $snapshot) {
                throw new PaymentException('No bank payment is in progress for this order.');
            }
            if ($snapshot->environment !== $this->settings->environment_name()) {
                throw new PaymentException('The payment environment changed; please restart the payment.');
            }
            try {
                $intent = $this->intents->get($intent_id);
            } catch (PlaidException $exception) {
                $this->logger->log('warning', 'completion_verification_unavailable', array('order_id' => $order->get_id(), 'transfer_intent_id' => $intent_id, 'request_id' => $exception->request_id(), 'error_code' => $exception->safe_code()));
                return new CompletionResult(CompletionResult::UNVERIFIED);
            }
            $order->update_meta_data(OrderMeta::TRANSFER_INTENT_STATUS, $intent->status);
            $order->update_meta_data(OrderMeta::REQUEST_ID, $intent->request_id);
            $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
            $order->save();

            if ($intent->id !== $intent_id || ! TransferBinder::intent_matches_snapshot($intent, $snapshot)) {
                $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'completion', 'reason' => 'intent_amount_mismatch'));
                return new CompletionResult(CompletionResult::INCOMPLETE, 'manual_review');
            }
            if (TransferIntent::SUCCEEDED === $intent->status) {
                $this->binder->bind($order, $intent, 'completion');
                $state = (string) wc_get_order($order->get_id())?->get_meta(OrderMeta::PAYMENT_STATE, true);
                return PaymentState::MANUAL_REVIEW === $state
                    ? new CompletionResult(CompletionResult::INCOMPLETE, 'manual_review')
                    : new CompletionResult(CompletionResult::SUBMITTED);
            }
            if (TransferIntent::FAILED === $intent->status) {
                $this->projector->transition($order, PaymentState::INTENT_FAILED, array('source' => 'completion', 'failure_code' => $intent->failure_code));
                return new CompletionResult(CompletionResult::FAILED, $intent->failure_code);
            }
            if (PaymentState::INTENT_CREATED === $state) {
                $this->projector->transition($order, PaymentState::INTENT_PENDING, array('source' => 'completion'));
            }
            // PENDING: not captured yet. A DECLINED/NSF decision may be retried by the customer.
            return new CompletionResult(CompletionResult::INCOMPLETE, 'DECLINED' === $intent->authorization_decision ? $intent->decision_rationale_code : '');
        });
    }
}
