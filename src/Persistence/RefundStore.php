<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Refund\RefundRecord;
use PayBridge\Plaid\Refund\RefundState;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; refund reservations and compare-and-set updates must bypass object caches.

/**
 * Durable record of every refund PayBridge creates or discovers (ADR-0016).
 *
 * - The idempotency key is unique: one intended refund can never get two rows, and the
 *   same key is what Plaid receives, so a retried create cannot create a second refund.
 * - One WooCommerce refund object maps to at most one row (unique wc_refund_id).
 * - Every status change is a compare-and-set on the previous status, so concurrent
 *   event workers, reconciliation and admin actions apply each transition exactly once.
 */
final class RefundStore
{
    public const LEASE_SECONDS = 120;

    /**
     * Reserves a refund before the remote create call.
     *
     * @param array{order_id:int, wc_refund_id:int, environment:string, account_fp:string, attempt_id:string, transfer_id:string, idempotency_key:string, amount:string, currency:string} $fields
     * @return array{record:RefundRecord|null, owner_token:string} record null = a row for this WooCommerce refund or key already exists.
     * @throws PersistenceException when the reservation cannot be written (fail closed: no remote call).
     */
    public function reserve(array $fields): array
    {
        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (order_id, wc_refund_id, environment, account_fp, attempt_id, transfer_id, idempotency_key, amount, currency, status, origin,
                                    owner_token, lease_expires_at, reconcile_after, checks, created_at, updated_at)
             VALUES (%d, NULLIF(%d, 0), %s, NULLIF(%s, ''), %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            Installer::refunds_table(),
            $fields['order_id'],
            $fields['wc_refund_id'],
            $fields['environment'],
            $fields['account_fp'],
            $fields['attempt_id'],
            $fields['transfer_id'],
            $fields['idempotency_key'],
            $fields['amount'],
            $fields['currency'],
            RefundState::CREATING,
            RefundRecord::ORIGIN_WOOCOMMERCE,
            $token,
            gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
            gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS + 60)
        ));
        if (false === $inserted) {
            throw new PersistenceException('The refund reservation could not be written.');
        }
        if (1 !== $inserted) {
            return array('record' => null, 'owner_token' => '');
        }
        return array('record' => $this->find_by_idempotency_key($fields['idempotency_key']), 'owner_token' => $token);
    }

    /**
     * Records a refund that exists at Plaid but was not created through PayBridge.
     *
     * @param array{order_id:int, environment:string, account_fp:string, attempt_id:string, transfer_id:string, refund_id:string, amount:string, currency:string, status:string, failure_code:string} $fields
     * @throws PersistenceException
     */
    public function insert_external(array $fields): ?RefundRecord
    {
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (order_id, wc_refund_id, environment, account_fp, attempt_id, transfer_id, refund_id, idempotency_key, amount, currency, status, origin,
                                    failure_code, checks, created_at, updated_at)
             VALUES (%d, NULL, %s, NULLIF(%s, ''), %s, %s, %s, %s, %s, %s, %s, %s, NULLIF(%s, ''), 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            Installer::refunds_table(),
            $fields['order_id'],
            $fields['environment'],
            $fields['account_fp'],
            $fields['attempt_id'],
            $fields['transfer_id'],
            $fields['refund_id'],
            substr('ext-' . $fields['refund_id'], 0, 50),
            $fields['amount'],
            $fields['currency'],
            $fields['status'],
            RefundRecord::ORIGIN_EXTERNAL,
            substr($fields['failure_code'], 0, 64)
        ));
        if (false === $inserted) {
            throw new PersistenceException('The external refund could not be recorded.');
        }
        return 1 === $inserted ? $this->find_by_refund_id($fields['environment'], $fields['refund_id']) : null;
    }

    public function find(int $id): ?RefundRecord
    {
        global $wpdb;
        return self::record($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', Installer::refunds_table(), $id), ARRAY_A));
    }

    public function find_by_wc_refund(int $wc_refund_id): ?RefundRecord
    {
        global $wpdb;
        if ($wc_refund_id < 1) {
            return null;
        }
        return self::record($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE wc_refund_id = %d LIMIT 1', Installer::refunds_table(), $wc_refund_id), ARRAY_A));
    }

    public function find_by_idempotency_key(string $key): ?RefundRecord
    {
        global $wpdb;
        if ('' === $key) {
            return null;
        }
        return self::record($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE idempotency_key = %s LIMIT 1', Installer::refunds_table(), $key), ARRAY_A));
    }

    public function find_by_refund_id(string $environment, string $refund_id): ?RefundRecord
    {
        global $wpdb;
        if ('' === $refund_id) {
            return null;
        }
        return self::record($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE environment = %s AND refund_id = %s LIMIT 1', Installer::refunds_table(), $environment, $refund_id), ARRAY_A));
    }

    /** @return list<RefundRecord> Oldest first. */
    public function for_order(int $order_id): array
    {
        global $wpdb;
        return self::records($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC LIMIT 100', Installer::refunds_table(), $order_id), ARRAY_A));
    }

    /** @return list<RefundRecord> Oldest first. */
    public function for_transfer(string $environment, string $transfer_id): array
    {
        global $wpdb;
        return self::records($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE environment = %s AND transfer_id = %s ORDER BY id ASC LIMIT 100', Installer::refunds_table(), $environment, $transfer_id), ARRAY_A));
    }

    /** The create call returned a refund: records its ID and status. Only the reservation owner may do this. */
    public function complete_creation(int $id, string $owner_token, string $refund_id, string $status, string $request_id): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE %i SET refund_id = %s, status = %s, request_id = NULLIF(%s, ''), owner_token = NULL, lease_expires_at = NULL, failure_code = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND owner_token = %s AND status = %s AND refund_id IS NULL",
            Installer::refunds_table(),
            $refund_id,
            $status,
            substr($request_id, 0, 64),
            $id,
            $owner_token,
            RefundState::CREATING
        ));
    }

    /** The create call ended without a refund ID: rejected (definitive) or uncertain (ambiguous). */
    public function finish_creation(int $id, string $owner_token, string $status, string $failure_code, string $request_id): bool
    {
        global $wpdb;
        if (! in_array($status, array(RefundState::REJECTED, RefundState::UNCERTAIN), true)) {
            return false;
        }
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s, failure_code = NULLIF(%s, ''), request_id = NULLIF(%s, ''), owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND owner_token = %s AND status = %s",
            Installer::refunds_table(),
            $status,
            substr($failure_code, 0, 64),
            substr($request_id, 0, 64),
            $id,
            $owner_token,
            RefundState::CREATING
        ));
    }

    /** Compare-and-set status change; false when another worker changed the row first. */
    public function transition(int $id, string $from, string $to, string $failure_code = '', string $event_id = ''): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s,
                    failure_code = IF(%s = '', failure_code, %s),
                    last_event_id = IF(%s = '', last_event_id, %s),
                    owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND status = %s",
            Installer::refunds_table(),
            $to,
            substr($failure_code, 0, 64),
            substr($failure_code, 0, 64),
            $event_id,
            '' === $event_id ? '0' : $event_id,
            $id,
            $from
        ));
    }

    /** Links a Plaid refund found for an uncertain or creating reservation (exactly once). */
    public function adopt(int $id, string $from, string $refund_id, string $to): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE %i SET refund_id = %s, status = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND status = %s AND refund_id IS NULL',
            Installer::refunds_table(),
            $refund_id,
            $to,
            $id,
            $from
        ));
    }

    public function link_wc_refund(int $id, int $wc_refund_id): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE %i SET wc_refund_id = %d, updated_at = UTC_TIMESTAMP() WHERE id = %d',
            Installer::refunds_table(),
            $wc_refund_id,
            $id
        ));
    }

    /** Next reconciliation check (null stops checks) and end of monitoring. */
    public function schedule(int $id, ?int $next, ?int $until): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET reconcile_after = NULLIF(%s, ''), monitor_until = NULLIF(%s, ''), checks = checks + 1, updated_at = UTC_TIMESTAMP() WHERE id = %d",
            Installer::refunds_table(),
            null === $next ? '' : gmdate('Y-m-d H:i:s', $next),
            null === $until ? '' : gmdate('Y-m-d H:i:s', $until),
            $id
        ));
    }

    /** A worker that died during the create call leaves an unknown outcome: never retried blindly. */
    public function expire_abandoned(): int
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s, failure_code = 'lease_expired', owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE status = %s AND lease_expires_at < UTC_TIMESTAMP()",
            Installer::refunds_table(),
            RefundState::UNCERTAIN,
            RefundState::CREATING
        ));
        return is_int($updated) ? $updated : 0;
    }

    /** @return list<RefundRecord> Refunds of the configured Plaid account whose next check is due. */
    public function due(string $environment, string $account_fp, int $limit): array
    {
        global $wpdb;
        return self::records($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE environment = %s AND (account_fp IS NULL OR %s = '' OR account_fp = %s) AND reconcile_after IS NOT NULL AND reconcile_after <= UTC_TIMESTAMP() ORDER BY reconcile_after ASC LIMIT %d",
            Installer::refunds_table(),
            $environment,
            $account_fp,
            $account_fp,
            max(1, min(100, $limit))
        ), ARRAY_A));
    }

    /** @return array<string, int> Refund counts per status in one environment. */
    public function counts(string $environment): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS total FROM %i WHERE environment = %s GROUP BY status', Installer::refunds_table(), $environment), ARRAY_A);
        $counts = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    /** Refunds that may still change and must stay readable with this Plaid account (ADR-0015). */
    public function open_count(string $environment, string $account_fp = ''): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE environment = %s AND (%s = '' OR account_fp IS NULL OR account_fp = %s)
               AND (status IN (%s, %s, %s, %s) OR reconcile_after IS NOT NULL)",
            Installer::refunds_table(),
            $environment,
            $account_fp,
            $account_fp,
            RefundState::CREATING,
            RefundState::UNCERTAIN,
            RefundState::PENDING,
            RefundState::POSTED
        ));
    }

    private static function record(mixed $row): ?RefundRecord
    {
        return is_array($row) ? RefundRecord::from_row($row) : null;
    }

    /** @return list<RefundRecord> */
    private static function records(mixed $rows): array
    {
        $records = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row)) {
                $records[] = RefundRecord::from_row($row);
            }
        }
        return $records;
    }
}
