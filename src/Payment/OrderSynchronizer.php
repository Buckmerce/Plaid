<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Payment;

use Buckmerce\Plaid\Exception\PaymentException;
use Buckmerce\Plaid\Persistence\DatabaseMutex;
use Buckmerce\Plaid\Plaid\DTO\TransferIntent;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentService;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Re-reads authoritative Plaid state for one order (manual "Sync with Plaid"
 * and reconciliation). Uses only read endpoints; safe to repeat.
 */
final class OrderSynchronizer
{
    public const RESULT_UPDATED = 'updated';
    public const RESULT_SKIPPED = 'skipped';

    public function __construct(
        private readonly Settings $settings,
        private readonly TransferIntentService $intents,
        private readonly TransferService $transfers,
        private readonly TransferBinder $binder,
        private readonly OrderPaymentProjector $projector
    ) {
    }

    /**
     * @throws PaymentException
     * @throws \Buckmerce\Plaid\Plaid\Exception\PlaidException
     */
    public function sync(\WC_Order $order): string
    {
        return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function () use ($order): string {
            $order = wc_get_order($order->get_id());
            if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
                throw new PaymentException('The order is not a Buckmerce order.');
            }
            $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
            $intent_id = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
            if (null === $snapshot || '' === $intent_id || $snapshot->environment !== $this->settings->environment_name()) {
                return self::RESULT_SKIPPED;
            }
            if (! OrderLocator::order_in_scope($order, $this->settings->account_scope())) {
                // Only the Plaid account that created the attempt can read it (ADR-0015, ADR-0018).
                return self::RESULT_SKIPPED;
            }
            $transfer_id = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
            if ('' === $transfer_id) {
                $intent = $this->intents->get($intent_id);
                $order->update_meta_data(OrderMeta::TRANSFER_INTENT_STATUS, $intent->status);
                $order->update_meta_data(OrderMeta::REQUEST_ID, $intent->request_id);
                $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
                $order->save();
                if (! TransferBinder::intent_matches_snapshot($intent, $snapshot)) {
                    $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'sync', 'reason' => 'intent_amount_mismatch'));
                } elseif (TransferIntent::SUCCEEDED === $intent->status) {
                    $this->binder->bind($order, $intent, 'sync');
                } elseif (TransferIntent::FAILED === $intent->status) {
                    $this->projector->transition($order, PaymentState::INTENT_FAILED, array('source' => 'sync', 'failure_code' => $intent->failure_code));
                }
                return self::RESULT_UPDATED;
            }
            $transfer = $this->transfers->get($transfer_id);
            if ($transfer->id !== $transfer_id || ! TransferBinder::transfer_matches_snapshot($transfer, $snapshot)) {
                $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'sync', 'reason' => 'transfer_amount_mismatch', 'transfer_id' => $transfer_id));
                return self::RESULT_UPDATED;
            }
            $order->update_meta_data(OrderMeta::REQUEST_ID, $transfer->request_id);
            $this->binder->apply_transfer_status($order, $transfer, 'sync');
            return self::RESULT_UPDATED;
        });
    }
}
