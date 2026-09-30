<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Background;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\OrderSynchronizer;
use PayBridge\Plaid\Payment\PaymentMonitor;
use PayBridge\Plaid\Persistence\DatabaseMutex;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Refund\RefundService;
use PayBridge\Plaid\Settings\Settings;

/**
 * Recovers from lost webhooks, interrupted workers and stale state using authoritative
 * Plaid reads. It maintains EXISTING payments and refunds, so it runs whether or not the
 * gateway currently accepts new payments (ADR-0014). Work per run is bounded and uses
 * indexed queries only; it never scans WooCommerce orders.
 */
final class ReconciliationService
{
    public const LAST_RUN_OPTION = 'paybridge_plaid_last_reconciliation';
    public const LAST_ERROR_OPTION = 'paybridge_plaid_last_reconciliation_error';

    public const ORDERS_PER_RUN = 25;
    public const REFUNDS_PER_RUN = 25;
    public const BACKFILL_PER_RUN = 25;
    /** Retry delay after a failed per-order check. */
    public const RETRY_SECONDS = 900;
    private const TIME_BUDGET_SECONDS = 45;

    public function __construct(
        private readonly Settings $settings,
        private readonly EventSyncService $event_sync,
        private readonly OrderSynchronizer $synchronizer,
        private readonly PaymentLockStore $locks,
        private readonly Logger $logger,
        private readonly PaymentMonitor $monitor,
        private readonly RefundService $refunds
    ) {
    }

    /** @return array{status:string, synced:int, refunds:int, more:bool} */
    public function run(): array
    {
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire('reconciliation')) {
            return array('status' => 'busy', 'synced' => 0, 'refunds' => 0, 'more' => false);
        }
        $deadline = time() + self::TIME_BUDGET_SECONDS;
        try {
            // 1. Lost-webhook recovery: pull every new payment and refund event.
            $events = $this->event_sync->run();
            // 2. Payments whose provider state must be re-read (bounded, oldest due first).
            $synced = 0;
            $candidates = $this->locks->due_for_reconciliation($this->settings->environment_name(), self::ORDERS_PER_RUN, $this->settings->account_fingerprint());
            foreach ($candidates as $order_id) {
                if (time() >= $deadline) {
                    break;
                }
                $synced += $this->reconcile_order($order_id) ? 1 : 0;
            }
            // 3. Refunds that are unconfirmed, in flight or inside their return monitoring.
            $refunds = time() < $deadline ? $this->refunds->reconcile_due(self::REFUNDS_PER_RUN) : 0;
            // 4. Index rows written before schema 2 get their monitoring projection.
            if (time() < $deadline) {
                $this->backfill();
            }
            update_option(self::LAST_RUN_OPTION, gmdate('c'), false);
            if ('failed' !== $events['status']) {
                delete_option(self::LAST_ERROR_OPTION);
            }
            $more = $events['more'] || count($candidates) >= self::ORDERS_PER_RUN || $refunds >= self::REFUNDS_PER_RUN || time() >= $deadline;
            return array('status' => 'ok', 'synced' => $synced, 'refunds' => $refunds, 'more' => $more);
        } catch (\Throwable $exception) {
            update_option(self::LAST_ERROR_OPTION, array('at' => gmdate('c'), 'code' => Logger::fingerprint($exception->getMessage()), 'category' => self::category($exception)), false);
            $this->logger->log('error', 'reconciliation_failed', array('error_code' => Logger::fingerprint($exception->getMessage()), 'category' => self::category($exception)));
            return array('status' => 'failed', 'synced' => 0, 'refunds' => 0, 'more' => false);
        } finally {
            $mutex->release();
        }
    }

    private function reconcile_order(int $order_id): bool
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            $this->locks->schedule_reconciliation($order_id, null);
            return false;
        }
        try {
            $this->synchronizer->sync($order);
            $fresh = wc_get_order($order_id);
            if ($fresh instanceof \WC_Order) {
                $this->monitor->refresh($fresh);
            }
            return true;
        } catch (\Throwable $exception) {
            // Transient and permanent failures both retry later; the check is bounded per run.
            $this->locks->schedule_reconciliation($order_id, self::RETRY_SECONDS);
            $this->logger->log('warning', 'reconciliation_order_failed', array('order_id' => $order_id, 'error_code' => Logger::fingerprint($exception->getMessage()), 'category' => self::category($exception)));
            return false;
        }
    }

    private function backfill(): void
    {
        foreach ($this->locks->missing_projection(self::BACKFILL_PER_RUN) as $order_id) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order) {
                $this->monitor->refresh($order);
            }
        }
    }

    /** transient (retry soon), permanent (configuration/credentials: merchant action) or local. */
    public static function category(\Throwable $exception): string
    {
        if ($exception instanceof PlaidApiException) {
            return $exception->is_transient() ? 'transient' : 'permanent';
        }
        if ($exception instanceof \PayBridge\Plaid\Exception\ConfigurationException) {
            return 'permanent';
        }
        if ($exception instanceof \PayBridge\Plaid\Plaid\Exception\PlaidException) {
            return 'transient';
        }
        return 'local';
    }
}
