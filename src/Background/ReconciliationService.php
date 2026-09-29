<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Background;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\OrderMeta;
use PayBridge\Plaid\Payment\OrderSynchronizer;
use PayBridge\Plaid\Payment\PaymentState;
use PayBridge\Plaid\Persistence\DatabaseMutex;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Settings\Settings;

/**
 * Recovers from lost webhooks, interrupted workers and stale orders using
 * authoritative Plaid reads. Work per run is bounded (never scans all orders).
 */
final class ReconciliationService
{
    public const LAST_RUN_OPTION = 'paybridge_plaid_last_reconciliation';
    public const LAST_ERROR_OPTION = 'paybridge_plaid_last_reconciliation_error';

    public const ORDERS_PER_RUN = 20;
    public const LOOKBACK_DAYS = 60;
    public const MIN_SYNC_INTERVAL_SECONDS = 900;

    /** States whose provider truth may still change. */
    public const OPEN_STATES = array(
        PaymentState::INTENT_CREATED,
        PaymentState::INTENT_PENDING,
        PaymentState::TRANSFER_CREATED,
        PaymentState::PENDING,
        PaymentState::POSTED,
        PaymentState::SETTLED,
        PaymentState::FUNDS_AVAILABLE,
    );

    public function __construct(
        private readonly Settings $settings,
        private readonly EventSyncService $event_sync,
        private readonly OrderSynchronizer $synchronizer,
        private readonly PaymentLockStore $locks,
        private readonly Logger $logger
    ) {
    }

    /** @return array{status:string, synced:int, more:bool} */
    public function run(): array
    {
        if (! $this->settings->reconciliation_enabled()) {
            return array('status' => 'disabled', 'synced' => 0, 'more' => false);
        }
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire('reconciliation')) {
            return array('status' => 'busy', 'synced' => 0, 'more' => false);
        }
        try {
            // 1. Lost-webhook recovery: pull every new event.
            $events = $this->event_sync->run();
            // 2. Stale orders: re-read provider truth for a bounded, oldest-first window.
            $synced = 0;
            $candidates = $this->stale_orders();
            foreach ($candidates as $order_id) {
                $order = wc_get_order($order_id);
                if (! $order instanceof \WC_Order) {
                    $this->locks->schedule_reconciliation($order_id, null);
                    continue;
                }
                try {
                    $this->synchronizer->sync($order);
                    ++$synced;
                    $fresh = wc_get_order($order_id);
                    $this->locks->schedule_reconciliation($order_id, $fresh instanceof \WC_Order ? $this->next_check($fresh) : null);
                } catch (\Throwable $exception) {
                    $this->locks->schedule_reconciliation($order_id, self::MIN_SYNC_INTERVAL_SECONDS);
                    $this->logger->log('warning', 'reconciliation_order_failed', array('order_id' => $order_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
                }
            }
            update_option(self::LAST_RUN_OPTION, gmdate('c'), false);
            if ('failed' !== $events['status']) {
                delete_option(self::LAST_ERROR_OPTION);
            }
            return array('status' => 'ok', 'synced' => $synced, 'more' => $events['more'] || count($candidates) >= self::ORDERS_PER_RUN);
        } catch (\Throwable $exception) {
            update_option(self::LAST_ERROR_OPTION, array('at' => gmdate('c'), 'code' => Logger::fingerprint($exception->getMessage())), false);
            $this->logger->log('error', 'reconciliation_failed', array('error_code' => Logger::fingerprint($exception->getMessage())));
            return array('status' => 'failed', 'synced' => 0, 'more' => false);
        } finally {
            $mutex->release();
        }
    }

    /** @return list<int> */
    private function stale_orders(): array
    {
        return $this->locks->due_for_reconciliation($this->settings->environment_name(), self::ORDERS_PER_RUN);
    }

    /** Decides when the order should be re-checked; null ends scheduled checks. */
    private function next_check(\WC_Order $order): ?int
    {
        $state = (string) $order->get_meta(OrderMeta::PAYMENT_STATE, true);
        if (! in_array($state, self::OPEN_STATES, true)) {
            return null;
        }
        $created = $order->get_date_created();
        if (null !== $created && $created->getTimestamp() < time() - self::LOOKBACK_DAYS * DAY_IN_SECONDS) {
            return null;
        }
        return in_array($state, array(PaymentState::INTENT_CREATED, PaymentState::INTENT_PENDING), true) ? self::MIN_SYNC_INTERVAL_SECONDS : HOUR_IN_SECONDS;
    }
}
