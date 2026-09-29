<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Plaid\DTO\TransferEvent;

/**
 * Durable idempotency store for Plaid transfer events.
 *
 * Identity is (environment, event_id). Recording is INSERT IGNORE, so replays
 * are harmless; processing uses an owner-token lease so a crashed worker's
 * event is reclaimed and an event can never be processed by two workers.
 */
final class TransferEventStore
{
    public const LEASE_SECONDS = 120;
    public const MAX_ATTEMPTS = 20;

    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const UNMATCHED = 'unmatched';
    public const RETRY = 'retry';
    public const ABANDONED = 'abandoned';

    /** @throws PersistenceException */
    public function record(string $environment, TransferEvent $event): void
    {
        global $wpdb;
        $data = wp_json_encode(array(
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'transfer_id' => $event->transfer_id,
            'transfer_type' => $event->transfer_type,
            'transfer_amount' => $event->transfer_amount,
            'intent_id' => $event->intent_id,
            'failure_code' => $event->failure_code,
            'ach_return_code' => $event->ach_return_code,
            'failure_description' => substr($event->failure_description, 0, 255),
            'timestamp' => $event->timestamp,
        ));
        $timestamp = strtotime($event->timestamp);
        $result = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Installer::events_table() . ' (environment, event_id, event_type, transfer_id, event_data, status, attempts, provider_created_at, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, 0, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            $environment,
            $event->event_id,
            substr($event->event_type, 0, 64),
            substr($event->transfer_id, 0, 64),
            (string) $data,
            $event->is_lifecycle_event() ? self::RECEIVED : self::IGNORED,
            false === $timestamp ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $timestamp)
        ));
        if (false === $result) {
            throw new PersistenceException('Transfer event could not be recorded.');
        }
    }

    /**
     * Claims up to $limit processable events in event_id order.
     *
     * @return list<array{id:int, owner_token:string, event:TransferEvent, attempts:int}>
     */
    public function claim_batch(string $environment, int $limit): array
    {
        global $wpdb;
        $table = Installer::events_table();
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE environment = %s AND attempts < %d
               AND (
                    status = %s
                 OR (status IN (%s, %s) AND lease_expires_at < UTC_TIMESTAMP())
                 OR (status = %s AND lease_expires_at < UTC_TIMESTAMP())
               )
             ORDER BY event_id ASC LIMIT %d",
            $environment,
            self::MAX_ATTEMPTS,
            self::RECEIVED,
            self::UNMATCHED,
            self::RETRY,
            self::PROCESSING,
            max(1, min(200, $limit))
        ));
        if (! is_array($candidates)) {
            throw new PersistenceException('Transfer events could not be read.');
        }
        $claimed = array();
        foreach ($candidates as $id) {
            $token = bin2hex(random_bytes(32));
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, owner_token = %s, lease_expires_at = %s, attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = %d AND attempts < %d
                   AND (status = %s OR (status IN (%s, %s, %s) AND lease_expires_at < UTC_TIMESTAMP()))",
                self::PROCESSING,
                $token,
                gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
                (int) $id,
                self::MAX_ATTEMPTS,
                self::RECEIVED,
                self::UNMATCHED,
                self::RETRY,
                self::PROCESSING
            ));
            if (1 !== $updated) {
                continue;
            }
            $row = $wpdb->get_row($wpdb->prepare("SELECT event_data, attempts FROM {$table} WHERE id = %d AND owner_token = %s", (int) $id, $token), ARRAY_A);
            $data = is_array($row) ? json_decode((string) $row['event_data'], true) : null;
            if (! is_array($data)) {
                $this->finish((int) $id, $token, self::ABANDONED, 'unreadable_event_data');
                continue;
            }
            $claimed[] = array(
                'id' => (int) $id,
                'owner_token' => $token,
                'event' => TransferEvent::from_array($data + array('failure_reason' => array(
                    'failure_code' => $data['failure_code'] ?? null,
                    'ach_return_code' => $data['ach_return_code'] ?? null,
                    'description' => $data['failure_description'] ?? null,
                )), ''),
                'attempts' => (int) $row['attempts'],
            );
        }
        return $claimed;
    }

    /** Terminal outcome for a claimed event. Returns false if the lease was lost. */
    public function finish(int $id, string $owner_token, string $status, string $error_code = '', int $order_id = 0): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::events_table() . " SET status = %s, error_code = NULLIF(%s, ''), order_id = NULLIF(%d, 0), owner_token = NULL,
                    lease_expires_at = NULL, processed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND owner_token = %s AND status = %s",
            $status,
            substr($error_code, 0, 64),
            $order_id,
            $id,
            $owner_token,
            self::PROCESSING
        ));
    }

    /** Releases a claimed event for a later retry with exponential backoff. */
    public function defer(int $id, string $owner_token, string $status, string $error_code, int $attempts): bool
    {
        global $wpdb;
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->finish($id, $owner_token, self::ABANDONED, $error_code);
        }
        $delay = min(6 * HOUR_IN_SECONDS, 60 * (2 ** min(8, max(0, $attempts - 1))));
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::events_table() . ' SET status = %s, error_code = %s, owner_token = NULL, lease_expires_at = %s, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND owner_token = %s AND status = %s',
            $status,
            substr($error_code, 0, 64),
            gmdate('Y-m-d H:i:s', time() + $delay),
            $id,
            $owner_token,
            self::PROCESSING
        ));
    }

    public function count_by_status(string $status): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE status = %s', $status));
    }

    public function has_processable(string $environment): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . Installer::events_table() . ' WHERE environment = %s AND attempts < %d AND (status = %s OR (status IN (%s, %s, %s) AND lease_expires_at < UTC_TIMESTAMP())) LIMIT 1',
            $environment,
            self::MAX_ATTEMPTS,
            self::RECEIVED,
            self::UNMATCHED,
            self::RETRY,
            self::PROCESSING
        ));
    }
}
