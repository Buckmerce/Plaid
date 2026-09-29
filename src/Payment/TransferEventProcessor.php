<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Persistence\DatabaseMutex;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\Plaid\DTO\TransferEvent;
use PayBridge\Plaid\Plaid\DTO\TransferIntent;
use PayBridge\Plaid\Plaid\Exception\PlaidException;
use PayBridge\Plaid\Plaid\Transfer\TransferService;
use PayBridge\Plaid\Plaid\TransferIntent\TransferIntentService;
use PayBridge\Plaid\Support\Money;

/**
 * Applies one durable Plaid transfer event (fetched via /transfer/event/sync)
 * to its WooCommerce order. Idempotent: replays are NOOP/STALE in the state machine.
 */
final class TransferEventProcessor
{
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly TransferIntentService $intents,
        private readonly TransferService $transfers,
        private readonly TransferBinder $binder,
        private readonly OrderPaymentProjector $projector,
        private readonly Logger $logger
    ) {
    }

    /** @return array{status:string, order_id:int, error_code:string} */
    public function process(TransferEvent $event, string $environment): array
    {
        if (! $event->is_lifecycle_event()) {
            return array('status' => TransferEventStore::IGNORED, 'order_id' => 0, 'error_code' => '');
        }
        $match = $this->locator->by_transfer_id($event->transfer_id);
        if (null !== $match && OrderLocator::MATCH_RETIRED === $match['match']) {
            return array('status' => TransferEventStore::IGNORED, 'order_id' => $match['order']->get_id(), 'error_code' => 'retired_attempt');
        }
        $order = null === $match ? $this->adopt($event, $environment) : $match['order'];
        if (is_array($order)) {
            return $order;
        }
        if (null === $order) {
            return array('status' => TransferEventStore::UNMATCHED, 'order_id' => 0, 'error_code' => 'transfer_not_bound');
        }

        try {
            return DatabaseMutex::with(DatabaseMutex::payment_resource($order->get_id()), function () use ($order, $event, $environment): array {
                $order = wc_get_order($order->get_id());
                if (! $order instanceof \WC_Order || $event->transfer_id !== (string) $order->get_meta(OrderMeta::TRANSFER_ID, true)) {
                    return array('status' => TransferEventStore::RETRY, 'order_id' => 0, 'error_code' => 'order_changed');
                }
                if ($environment !== (string) $order->get_meta(OrderMeta::ENVIRONMENT, true)) {
                    return array('status' => TransferEventStore::IGNORED, 'order_id' => $order->get_id(), 'error_code' => 'environment_mismatch');
                }
                $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::PAYMENT_SNAPSHOT, true));
                if (null === $snapshot || ('' !== $event->transfer_amount && ! Money::same_amount($event->transfer_amount, $snapshot->amount))) {
                    $this->projector->transition($order, PaymentState::MANUAL_REVIEW, array('source' => 'event', 'reason' => 'event_amount_mismatch', 'transfer_id' => $event->transfer_id, 'event_id' => $event->event_id));
                    return array('status' => TransferEventStore::PROCESSED, 'order_id' => $order->get_id(), 'error_code' => 'event_amount_mismatch');
                }
                $state = PaymentState::from_plaid_transfer_status($event->event_type);
                if (null === $state) {
                    return array('status' => TransferEventStore::IGNORED, 'order_id' => $order->get_id(), 'error_code' => '');
                }
                $decision = $this->projector->transition($order, $state, array(
                    'source' => 'event',
                    'transfer_id' => $event->transfer_id,
                    'event_id' => $event->event_id,
                    'failure_code' => $event->failure_code,
                    'return_code' => $event->return_code(),
                    'description' => $event->failure_description,
                ));
                $order = wc_get_order($order->get_id());
                if ($order instanceof \WC_Order) {
                    $last = (string) $order->get_meta(OrderMeta::LAST_EVENT_ID, true);
                    if ('' === $last || \PayBridge\Plaid\Support\Decimal::compare($event->event_id, $last) > 0) {
                        $order->update_meta_data(OrderMeta::LAST_EVENT_ID, $event->event_id);
                    }
                    if ('apply' === $decision) {
                        $order->update_meta_data(OrderMeta::TRANSFER_STATUS, $event->event_type);
                    }
                    $order->update_meta_data(OrderMeta::LAST_SYNC_AT, gmdate('c'));
                    $order->save();
                }
                return array('status' => TransferEventStore::PROCESSED, 'order_id' => $order instanceof \WC_Order ? $order->get_id() : 0, 'error_code' => 'conflict' === $decision ? 'state_conflict' : '');
            });
        } catch (\PayBridge\Plaid\Exception\PaymentAttemptBusyException $exception) {
            return array('status' => TransferEventStore::RETRY, 'order_id' => $order->get_id(), 'error_code' => 'order_busy');
        }
    }

    /**
     * The transfer is not yet bound (the customer closed the page before the
     * completion check). Correlate through data PayBridge itself wrote at intent
     * creation, then confirm with the authoritative intent before binding.
     *
     * @return \WC_Order|array{status:string, order_id:int, error_code:string}|null
     */
    private function adopt(TransferEvent $event, string $environment): \WC_Order|array|null
    {
        try {
            $candidate = null;
            $attempt_id = '';
            if ('' !== $event->intent_id) {
                $match = $this->locator->by_intent_id($event->intent_id);
                $candidate = null !== $match && OrderLocator::MATCH_ACTIVE === $match['match'] ? $match['order'] : null;
            }
            if (null === $candidate) {
                $transfer = $this->transfers->get($event->transfer_id);
                $order_id = (int) ($transfer->metadata['pbfp_order_id'] ?? 0);
                $attempt_id = (string) ($transfer->metadata['pbfp_attempt_id'] ?? '');
                $candidate = $order_id > 0 ? $this->locator->paybridge_order($order_id) : null;
            }
            if (null === $candidate || $environment !== (string) $candidate->get_meta(OrderMeta::ENVIRONMENT, true)) {
                return null;
            }
            if ('' !== $attempt_id && self::is_retired_attempt($candidate, $attempt_id)) {
                return array('status' => TransferEventStore::IGNORED, 'order_id' => $candidate->get_id(), 'error_code' => 'retired_attempt');
            }
            $intent_id = (string) $candidate->get_meta(OrderMeta::TRANSFER_INTENT_ID, true);
            if ('' === $intent_id) {
                return null;
            }
            return DatabaseMutex::with(DatabaseMutex::payment_resource($candidate->get_id()), function () use ($candidate, $intent_id, $event): ?\WC_Order {
                $intent = $this->intents->get($intent_id);
                if (TransferIntent::SUCCEEDED !== $intent->status || $intent->transfer_id !== $event->transfer_id) {
                    return null;
                }
                $order = wc_get_order($candidate->get_id());
                if (! $order instanceof \WC_Order) {
                    return null;
                }
                $this->binder->bind($order, $intent, 'event');
                $this->logger->log('info', 'transfer_adopted_from_event', array('order_id' => $order->get_id(), 'transfer_id' => $event->transfer_id, 'event_id' => $event->event_id));
                $bound = wc_get_order($order->get_id());
                return $bound instanceof \WC_Order && $event->transfer_id === (string) $bound->get_meta(OrderMeta::TRANSFER_ID, true) ? $bound : null;
            });
        } catch (PlaidException | \PayBridge\Plaid\Exception\PaymentAttemptBusyException $exception) {
            return null;
        }
    }

    private static function is_retired_attempt(\WC_Order $order, string $attempt_id): bool
    {
        $retired = $order->get_meta(OrderMeta::RETIRED_ATTEMPTS, true);
        foreach (is_array($retired) ? $retired : array() as $attempt) {
            $snapshot = is_array($attempt) ? PaymentSnapshot::from_json((string) ($attempt['snapshot'] ?? '')) : null;
            if (null !== $snapshot && hash_equals($snapshot->attempt_id, $attempt_id)) {
                return true;
            }
        }
        return false;
    }
}
