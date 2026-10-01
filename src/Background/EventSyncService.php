<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Background;

use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\TransferEventProcessor;
use Buckmerce\Plaid\Persistence\DatabaseMutex;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\TransferEventStore;
use Buckmerce\Plaid\Plaid\Transfer\TransferEventService;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Support\Decimal;

/**
 * /transfer/event/sync ingestion for ONE Plaid event stream (environment + account, ADR-0018)
 * (docs/WEBHOOKS_AND_EVENTS.md):
 *
 *   fetch page → durably record every event → advance cursor → process rows
 *
 * The cursor never moves past an event that is not in the event store, so a crash at any
 * point loses nothing; processing is lease-based and idempotent. The client, the cursor, the
 * stored events, the mutex and the health record all belong to the same scope, so another
 * Plaid account's stream can never advance, dedupe or process this one.
 */
final class EventSyncService
{
    /** Per-scope health record: last successful sync, last error, consecutive failures. */
    public const HEALTH_OPTION_PREFIX = 'buckmerce_plaid_event_sync_';
    /** Schema-2 per-site options, superseded by the per-scope health record (kept only for uninstall). */
    public const LEGACY_OPTIONS = array('buckmerce_plaid_last_event_sync', 'buckmerce_plaid_last_event_sync_error', 'buckmerce_plaid_event_sync_failures');
    public const MAX_RETRY_DELAY_SECONDS = 900;

    private const MAX_PAGES = 10;
    private const MAX_EVENTS_PER_RUN = 200;
    private const TIME_BUDGET_SECONDS = 40;

    public function __construct(
        private readonly AccountScope $scope,
        private readonly TransferEventService $events,
        private readonly TransferEventStore $store,
        private readonly EventCursor $cursor,
        private readonly TransferEventProcessor $processor,
        private readonly Logger $logger
    ) {
    }

    public function scope(): AccountScope
    {
        return $this->scope;
    }

    /** @return array{status:string, fetched:int, processed:int, more:bool} */
    public function run(): array
    {
        if (! $this->scope->is_valid()) {
            return array('status' => 'failed', 'fetched' => 0, 'processed' => 0, 'more' => false);
        }
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire(self::mutex_resource($this->scope))) {
            return array('status' => 'busy', 'fetched' => 0, 'processed' => 0, 'more' => true);
        }
        $deadline = time() + self::TIME_BUDGET_SECONDS;
        $fetched = 0;
        $processed = 0;
        $has_more = false;
        try {
            for ($page = 0; $page < self::MAX_PAGES && time() < $deadline; ++$page) {
                $mutex->assert_owned();
                $after = $this->cursor->get($this->scope);
                $result = $this->events->sync($after);
                $highest = $after;
                foreach ($result->events as $event) {
                    $this->store->record($this->scope, $event);
                    if (Decimal::compare($event->event_id, $highest) > 0) {
                        $highest = $event->event_id;
                    }
                }
                $fetched += count($result->events);
                $this->cursor->advance($this->scope, $highest);
                $has_more = $result->has_more && array() !== $result->events;
                if (! $has_more) {
                    break;
                }
            }
            self::record_health($this->scope, array('last_sync' => gmdate('c'), 'last_error' => array(), 'failures' => 0));
            while (time() < $deadline && $processed < self::MAX_EVENTS_PER_RUN) {
                $batch = $this->store->claim_batch($this->scope, 25);
                if (array() === $batch) {
                    break;
                }
                foreach ($batch as $claim) {
                    $processed += $this->process_claim($claim);
                }
            }
        } catch (\Throwable $exception) {
            $category = ReconciliationService::category($exception);
            $code = $exception instanceof \Buckmerce\Plaid\Plaid\Exception\PlaidException ? $exception->safe_code() : Logger::fingerprint($exception->getMessage());
            self::record_health($this->scope, array('last_error' => array('at' => gmdate('c'), 'code' => $code, 'category' => $category), 'failures' => self::failures($this->scope) + 1));
            $this->logger->log('error', 'event_sync_failed', array('environment' => $this->scope->environment, 'account_fp' => $this->scope->account_fp, 'error_code' => $code, 'category' => $category));
            return array('status' => 'failed', 'fetched' => $fetched, 'processed' => $processed, 'more' => true);
        } finally {
            $mutex->release();
        }
        $more = $has_more || $this->store->has_processable($this->scope);
        return array('status' => 'ok', 'fetched' => $fetched, 'processed' => $processed, 'more' => $more);
    }

    /** One event-sync worker per Plaid event stream; different accounts never share a lock. */
    public static function mutex_resource(AccountScope $scope): string
    {
        return 'event-sync:' . $scope->environment . ':' . $scope->account_fp;
    }

    /** @return array{last_sync:string, last_error:array<string, string>, failures:int} */
    public static function health(AccountScope $scope): array
    {
        $stored = get_option(self::HEALTH_OPTION_PREFIX . $scope->key(), array());
        $stored = is_array($stored) ? $stored : array();
        $error = is_array($stored['last_error'] ?? null) ? array_map('strval', array_filter($stored['last_error'], 'is_scalar')) : array();
        return array(
            'last_sync' => is_string($stored['last_sync'] ?? null) ? $stored['last_sync'] : '',
            'last_error' => $error,
            'failures' => is_numeric($stored['failures'] ?? null) ? max(0, (int) $stored['failures']) : 0,
        );
    }

    public static function failures(AccountScope $scope): int
    {
        return self::health($scope)['failures'];
    }

    /** Delay before a follow-up run: immediate progress when healthy, exponential backoff (max 15 min) after failures. */
    public static function retry_delay(AccountScope $scope): int
    {
        $failures = self::failures($scope);
        return 0 === $failures ? MINUTE_IN_SECONDS : min(self::MAX_RETRY_DELAY_SECONDS, MINUTE_IN_SECONDS * (2 ** min(6, $failures - 1)));
    }

    /** @param array<string, mixed> $changes */
    private static function record_health(AccountScope $scope, array $changes): void
    {
        update_option(self::HEALTH_OPTION_PREFIX . $scope->key(), $changes + self::health($scope), false);
    }

    /** @param array{id:int, owner_token:string, event:\Buckmerce\Plaid\Plaid\DTO\TransferEvent, attempts:int} $claim */
    private function process_claim(array $claim): int
    {
        try {
            $result = $this->processor->process($claim['event'], $this->scope);
        } catch (\Throwable $exception) {
            $this->logger->log('error', 'transfer_event_failed', array('event_id' => $claim['event']->event_id, 'transfer_id' => $claim['event']->transfer_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            $this->store->defer($claim['id'], $claim['owner_token'], TransferEventStore::RETRY, 'processing_error', $claim['attempts']);
            return 0;
        }
        if (in_array($result['status'], array(TransferEventStore::UNMATCHED, TransferEventStore::RETRY), true)) {
            $this->store->defer($claim['id'], $claim['owner_token'], $result['status'], $result['error_code'], $claim['attempts']);
            if (TransferEventStore::UNMATCHED === $result['status']) {
                $this->logger->log('warning', 'transfer_event_unmatched', array('event_id' => $claim['event']->event_id, 'transfer_id' => $claim['event']->transfer_id));
            }
            return 0;
        }
        if (! $this->store->finish($claim['id'], $claim['owner_token'], $result['status'], $result['error_code'], $result['order_id'])) {
            // Lease lost: another worker reclaimed it; state transitions are idempotent.
            $this->logger->log('warning', 'transfer_event_lease_lost', array('event_id' => $claim['event']->event_id));
        }
        return 1;
    }
}
