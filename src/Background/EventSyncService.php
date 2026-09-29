<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Background;

use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\TransferEventProcessor;
use PayBridge\Plaid\Persistence\DatabaseMutex;
use PayBridge\Plaid\Persistence\EventCursor;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\Plaid\Transfer\TransferEventService;
use PayBridge\Plaid\Support\Decimal;

/**
 * /transfer/event/sync ingestion (docs/WEBHOOKS_AND_EVENTS.md):
 *
 *   fetch page → durably record every event → advance cursor → process rows
 *
 * The cursor never moves past an event that is not in the event store, so a
 * crash at any point loses nothing; processing is lease-based and idempotent.
 */
final class EventSyncService
{
    public const LAST_SYNC_OPTION = 'paybridge_plaid_last_event_sync';
    public const LAST_ERROR_OPTION = 'paybridge_plaid_last_event_sync_error';

    private const MAX_PAGES = 10;
    private const MAX_EVENTS_PER_RUN = 200;
    private const TIME_BUDGET_SECONDS = 40;

    public function __construct(
        private readonly string $environment,
        private readonly TransferEventService $events,
        private readonly TransferEventStore $store,
        private readonly EventCursor $cursor,
        private readonly TransferEventProcessor $processor,
        private readonly Logger $logger
    ) {
    }

    /** @return array{status:string, fetched:int, processed:int, more:bool} */
    public function run(): array
    {
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire('event-sync:' . $this->environment)) {
            return array('status' => 'busy', 'fetched' => 0, 'processed' => 0, 'more' => true);
        }
        $deadline = time() + self::TIME_BUDGET_SECONDS;
        $fetched = 0;
        $processed = 0;
        $has_more = false;
        try {
            for ($page = 0; $page < self::MAX_PAGES && time() < $deadline; ++$page) {
                $mutex->assert_owned();
                $after = $this->cursor->get($this->environment);
                $result = $this->events->sync($after);
                $highest = $after;
                foreach ($result->events as $event) {
                    $this->store->record($this->environment, $event);
                    if (Decimal::compare($event->event_id, $highest) > 0) {
                        $highest = $event->event_id;
                    }
                }
                $fetched += count($result->events);
                $this->cursor->advance($this->environment, $highest);
                $has_more = $result->has_more && array() !== $result->events;
                if (! $has_more) {
                    break;
                }
            }
            update_option(self::LAST_SYNC_OPTION, gmdate('c'), false);
            delete_option(self::LAST_ERROR_OPTION);
            while (time() < $deadline && $processed < self::MAX_EVENTS_PER_RUN) {
                $batch = $this->store->claim_batch($this->environment, 25);
                if (array() === $batch) {
                    break;
                }
                foreach ($batch as $claim) {
                    $processed += $this->process_claim($claim);
                }
            }
        } catch (\Throwable $exception) {
            update_option(self::LAST_ERROR_OPTION, array('at' => gmdate('c'), 'code' => Logger::fingerprint($exception->getMessage())), false);
            $this->logger->log('error', 'event_sync_failed', array('environment' => $this->environment, 'error_code' => Logger::fingerprint($exception->getMessage())));
            return array('status' => 'failed', 'fetched' => $fetched, 'processed' => $processed, 'more' => true);
        } finally {
            $mutex->release();
        }
        $more = $has_more || $this->store->has_processable($this->environment);
        return array('status' => 'ok', 'fetched' => $fetched, 'processed' => $processed, 'more' => $more);
    }

    /** @param array{id:int, owner_token:string, event:\PayBridge\Plaid\Plaid\DTO\TransferEvent, attempts:int} $claim */
    private function process_claim(array $claim): int
    {
        try {
            $result = $this->processor->process($claim['event'], $this->environment);
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
