<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Plaid\DTO\Transfer;
use PayBridge\Plaid\Plaid\DTO\TransferIntent;
use PayBridge\Plaid\Plaid\Transfer\TransferService;
use PayBridge\Plaid\Support\Money;

/**
 * Binds the transfer created from a SUCCEEDED Transfer Intent to its order,
 * after validating Plaid's authoritative objects against the immutable snapshot.
 * Callers must hold the order's payment mutex.
 */
final class TransferBinder
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly OrderPaymentProjector $projector,
        private readonly PaymentLockStore $locks
    ) {
    }

    public function bind(\WC_Order $order, TransferIntent $intent, string $source): void
    {
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
        $stored_intent = (string) $order->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
        if (null === $snapshot || $stored_intent !== $intent->id || TransferIntent::SUCCEEDED !== $intent->status || '' === $intent->transfer_id) {
            $this->quarantine($order, 'intent_binding_mismatch', $source, $intent->transfer_id);
            return;
        }
        if (! self::intent_matches_snapshot($intent, $snapshot)) {
            $this->quarantine($order, 'intent_amount_mismatch', $source, $intent->transfer_id);
            return;
        }
        $stored_transfer = (string) $order->get_meta(OrderMeta::TRANSFER_ID, true);
        if ('' !== $stored_transfer && $stored_transfer !== $intent->transfer_id) {
            $this->quarantine($order, 'transfer_id_conflict', $source, $intent->transfer_id);
            return;
        }

        $transfer = $this->transfers->get($intent->transfer_id);
        if (! self::transfer_matches_snapshot($transfer, $snapshot)) {
            $this->quarantine($order, 'transfer_amount_mismatch', $source, $transfer->id);
            return;
        }

        // The payment index is written first; without it events could not find the order.
        if (! $this->locks->bind_transfer($order->get_id(), $intent->id, $transfer->id)) {
            throw new PersistenceException('The transfer could not be recorded in the payment index.');
        }
        $order->update_meta_data(OrderMeta::TRANSFER_ID, $transfer->id);
        $order->update_meta_data(OrderMeta::TRANSFER_INTENT_STATUS, $intent->status);
        $order->update_meta_data(OrderMeta::TRANSFER_STATUS, $transfer->status);
        $order->update_meta_data(OrderMeta::REQUEST_ID, $transfer->request_id);
        $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
        OrderPersistence::save($order, array(OrderMeta::TRANSFER_ID => $transfer->id));
        if ('' === $stored_transfer) {
            $order->set_transaction_id($transfer->id);
            $order->save();
        }
        $this->projector->transition($order, PaymentState::TRANSFER_CREATED, array('source' => $source, 'transfer_id' => $transfer->id));
        $this->apply_transfer_status($order, $transfer, $source);
    }

    /** Projects an authoritative /transfer/get status (stale/duplicate safe). */
    public function apply_transfer_status(\WC_Order $order, Transfer $transfer, string $source): void
    {
        $state = PaymentState::from_plaid_transfer_status($transfer->status);
        if (null === $state) {
            return;
        }
        $this->projector->transition($order, $state, array(
            'source' => $source,
            'transfer_id' => $transfer->id,
            'failure_code' => $transfer->failure_code,
            'return_code' => '' !== $transfer->failure_code ? $transfer->failure_code : $transfer->ach_return_code,
            'description' => $transfer->failure_description,
        ));
        $order->update_meta_data(OrderMeta::TRANSFER_STATUS, $transfer->status);
        $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
        $order->save();
    }

    public static function intent_matches_snapshot(TransferIntent $intent, PaymentSnapshot $snapshot): bool
    {
        $attempt = $intent->metadata['pbfp_attempt_id'] ?? '';
        return Money::same_amount($intent->amount, $snapshot->amount)
            && ('' === $intent->iso_currency_code || $snapshot->currency === $intent->iso_currency_code)
            && 'PAYMENT' === $intent->mode
            && ('' === $attempt || hash_equals($snapshot->attempt_id, $attempt));
    }

    public static function transfer_matches_snapshot(Transfer $transfer, PaymentSnapshot $snapshot): bool
    {
        return Money::same_amount($transfer->amount, $snapshot->amount)
            && ('' === $transfer->iso_currency_code || $snapshot->currency === $transfer->iso_currency_code)
            && ('' === $transfer->type || 'debit' === $transfer->type);
    }

    private function quarantine(\WC_Order $order, string $reason, string $source, string $transfer_id): void
    {
        $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => $source, 'reason' => $reason, 'transfer_id' => $transfer_id));
    }
}
