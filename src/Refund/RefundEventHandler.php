<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Refund;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\AttemptHistory;
use PayBridge\Plaid\Payment\OrderLocator;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\PaymentSnapshot;
use PayBridge\Plaid\Persistence\PaymentEpoch;
use PayBridge\Plaid\Persistence\RefundStore;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\Plaid\DTO\TransferEvent;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Plaid\Exception\PlaidException;
use PayBridge\Plaid\Plaid\Refund\TransferRefundService;
use PayBridge\Plaid\Plaid\Transfer\TransferService;
use PayBridge\Plaid\Settings\AccountScope;
use PayBridge\Plaid\Support\Money;
use PayBridge\Plaid\Support\SiteMarker;

/**
 * Applies one durable refund event (event_type "refund.*", non-null refund_id) fetched by
 * /transfer/event/sync after a verified TRANSFER_EVENTS_UPDATE webhook or reconciliation.
 * The same pipeline as payment events: idempotent, retry-safe, out-of-order safe. Refund
 * identity and ownership are limited to the Plaid account whose stream delivered the event
 * (ADR-0018): another account's refund with the same ID is never matched or adopted.
 */
final class RefundEventHandler
{
    /**
     * @param \Closure(): TransferRefundService $plaid_refunds
     * @param \Closure(): TransferService       $transfers
     */
    public function __construct(
        private readonly RefundService $refunds,
        private readonly RefundStore $store,
        private readonly OrderLocator $locator,
        private readonly \Closure $plaid_refunds,
        private readonly \Closure $transfers,
        private readonly Logger $logger
    ) {
    }

    /** @return array{status:string, order_id:int, error_code:string} */
    public function process(TransferEvent $event, AccountScope $scope): array
    {
        $status = $event->refund_status();
        $record = $this->store->find_by_refund_id($scope, $event->refund_id);
        if (null !== $record) {
            $decision = $this->refunds->apply_status($record, $status, array('source' => 'event', 'failure_code' => $event->return_code(), 'event_id' => $event->event_id));
            return array('status' => TransferEventStore::PROCESSED, 'order_id' => $record->order_id, 'error_code' => 'conflict' === $decision ? 'refund_state_conflict' : '');
        }

        // A refund PayBridge reserved but has not linked yet (create response lost or still in flight).
        foreach ($this->store->for_transfer($scope, $event->transfer_id) as $candidate) {
            if ('' !== $candidate->refund_id || ! in_array($candidate->status, array(RefundState::CREATING, RefundState::UNCERTAIN), true)) {
                continue;
            }
            if ('' !== $event->transfer_amount && ! Money::same_amount($event->transfer_amount, $candidate->amount)) {
                continue;
            }
            if (RefundState::CREATING === $candidate->status && $candidate->lease_is_live()) {
                // The request that created it will record it; try this event again later.
                return array('status' => TransferEventStore::RETRY, 'order_id' => $candidate->order_id, 'error_code' => 'refund_creation_in_progress');
            }
            try {
                $refund = ($this->plaid_refunds)()->get($event->refund_id);
            } catch (PlaidException $exception) {
                return array('status' => TransferEventStore::RETRY, 'order_id' => $candidate->order_id, 'error_code' => $exception->safe_code());
            }
            if ($refund->transfer_id === $candidate->transfer_id && Money::same_amount($refund->amount, $candidate->amount)) {
                if (RefundState::CREATING === $candidate->status) {
                    $this->store->expire_abandoned();
                    $candidate = $this->store->find($candidate->id) ?? $candidate;
                }
                $this->refunds->adopt($candidate, $refund, 'event');
                return array('status' => TransferEventStore::PROCESSED, 'order_id' => $candidate->order_id, 'error_code' => '');
            }
        }

        // A refund created outside PayBridge. Record it when the transfer is one of this store's payments.
        if (PaymentEpoch::predates($scope, $event->timestamp)) {
            return array('status' => TransferEventStore::IGNORED, 'order_id' => 0, 'error_code' => 'before_first_payment');
        }
        try {
            $owner = $this->owner($event, $scope);
        } catch (PlaidApiException $exception) {
            return $exception->is_transient()
                ? array('status' => TransferEventStore::UNMATCHED, 'order_id' => 0, 'error_code' => $exception->safe_code())
                : array('status' => TransferEventStore::IGNORED, 'order_id' => 0, 'error_code' => 'transfer_unreadable');
        } catch (PlaidException $exception) {
            return array('status' => TransferEventStore::UNMATCHED, 'order_id' => 0, 'error_code' => $exception->safe_code());
        }
        if (null === $owner) {
            return array('status' => TransferEventStore::IGNORED, 'order_id' => 0, 'error_code' => 'foreign_refund');
        }
        try {
            $refund = ($this->plaid_refunds)()->get($event->refund_id);
        } catch (PlaidException $exception) {
            return array('status' => TransferEventStore::RETRY, 'order_id' => $owner['order']->get_id(), 'error_code' => $exception->safe_code());
        }
        if ($refund->transfer_id !== $event->transfer_id) {
            return array('status' => TransferEventStore::IGNORED, 'order_id' => $owner['order']->get_id(), 'error_code' => 'refund_transfer_mismatch');
        }
        $this->refunds->record_external($owner['order'], $refund, $owner['attempt_id'], $scope);
        return array('status' => TransferEventStore::PROCESSED, 'order_id' => $owner['order']->get_id(), 'error_code' => 'external_refund');
    }

    /**
     * The PayBridge order of a transfer: through the payment index (current or retired attempt)
     * or, for older attempts, through the correlation metadata PayBridge wrote on the intent.
     *
     * @return array{order:\WC_Order, attempt_id:string}|null
     * @throws PlaidException
     */
    private function owner(TransferEvent $event, AccountScope $scope): ?array
    {
        $match = $this->locator->by_transfer_id($event->transfer_id, $scope);
        if (null !== $match && OrderLocator::order_in_scope($match['order'], $scope)) {
            $snapshot = PaymentSnapshot::from_json((string) $match['order']->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
            return array('order' => $match['order'], 'attempt_id' => null === $snapshot ? '' : $snapshot->attempt_id);
        }
        $transfer = ($this->transfers)()->get($event->transfer_id);
        $metadata = $transfer->metadata;
        $order_id = (int) ($metadata['pbfp_order_id'] ?? 0);
        $attempt_id = (string) ($metadata['pbfp_attempt_id'] ?? '');
        if ($order_id < 1 || '' === $attempt_id) {
            return null;
        }
        if (isset($metadata['pbfp_site']) && ! hash_equals(SiteMarker::current(), (string) $metadata['pbfp_site'])) {
            return null;
        }
        if (isset($metadata['pbfp_environment']) && $scope->environment !== $metadata['pbfp_environment']) {
            return null;
        }
        $order = $this->locator->paybridge_order($order_id);
        if (null === $order || ! AttemptHistory::belongs_to($order, $attempt_id) || ! AttemptHistory::attempt_in_scope($order, $attempt_id, $scope)) {
            // Order numbers repeat across stores; only an attempt this order really made counts.
            $this->logger->log('warning', 'refund_event_order_missing', array('order_id' => $order_id, 'transfer_id' => $event->transfer_id, 'event_id' => $event->event_id));
            return null;
        }
        return array('order' => $order, 'attempt_id' => $attempt_id);
    }
}
