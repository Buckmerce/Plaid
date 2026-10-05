<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Refund;

use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\AttemptHistory;
use Buckmerce\Plaid\Payment\OrderLocator;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Persistence\TransferEventStore;
use Buckmerce\Plaid\Plaid\DTO\TransferEvent;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Exception\PlaidException;
use Buckmerce\Plaid\Plaid\Refund\TransferRefundService;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Support\Money;
use Buckmerce\Plaid\Support\SiteMarker;

/**
 * Applies one durable refund event (event_type "refund.*", non-null refund_id) fetched by
 * /transfer/event/sync after a verified TRANSFER_EVENTS_UPDATE webhook or reconciliation.
 * The same pipeline as payment events: idempotent, retry-safe, out-of-order safe. Refund
 * identity and ownership are limited to the Plaid account whose stream delivered the event.
 * Another account's refund with the same ID is never matched or adopted.
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

        // A refund Buckmerce reserved but has not linked yet (create response lost or still in flight).
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

        // A refund created outside Buckmerce. Record it when the transfer is one of this store's payments.
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
     * The Buckmerce order of a transfer: through the payment index (current or retired attempt)
     * or, for older attempts, through the correlation metadata Buckmerce wrote on the intent.
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
        $order_id = (int) ($metadata['bmfp_order_id'] ?? 0);
        $attempt_id = (string) ($metadata['bmfp_attempt_id'] ?? '');
        if ($order_id < 1 || '' === $attempt_id) {
            return null;
        }
        if (isset($metadata['bmfp_site']) && ! hash_equals(SiteMarker::current(), (string) $metadata['bmfp_site'])) {
            return null;
        }
        if (isset($metadata['bmfp_environment']) && $scope->environment !== $metadata['bmfp_environment']) {
            return null;
        }
        $order = $this->locator->buckmerce_order($order_id);
        if (null === $order || ! AttemptHistory::belongs_to($order, $attempt_id) || ! AttemptHistory::attempt_in_scope($order, $attempt_id, $scope)) {
            // Order numbers repeat across stores; only an attempt this order really made counts.
            $this->logger->log('warning', 'refund_event_order_missing', array('order_id' => $order_id, 'transfer_id' => $event->transfer_id, 'event_id' => $event->event_id));
            return null;
        }
        return array('order' => $order, 'attempt_id' => $attempt_id);
    }
}
