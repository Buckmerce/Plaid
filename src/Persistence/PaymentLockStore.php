<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

/**
 * Durable, database-backed fence around Transfer Intent creation for one order.
 *
 * Every state change is a compare-and-set on the owner token, so a worker that
 * lost its reservation can never overwrite a newer attempt.
 */
final class PaymentLockStore
{
    public const LEASE_SECONDS = 120;
    public const RECONCILE_FIRST_CHECK_SECONDS = 900;

    public function acquire(int $order_id, string $environment, string $attempt_id): PaymentReservation
    {
        global $wpdb;
        $table = Installer::locks_table();
        $token = bin2hex(random_bytes(32));
        $lease = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        // INSERT IGNORE turns only the expected primary-key collision into a
        // deterministic branch; a real DB error returns false and fails closed.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, environment, status, owner_token, lease_expires_at, attempts, attempt_id, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, 1, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            $order_id,
            $environment,
            PaymentLockStatus::PREPARING,
            $token,
            $lease,
            $attempt_id
        ));
        if (false === $inserted) {
            return new PaymentReservation(PaymentReservation::ERROR);
        }
        if (1 === $inserted) {
            return new PaymentReservation(PaymentReservation::ACQUIRED, $token);
        }
        if (! $this->expire_abandoned($order_id)) {
            return new PaymentReservation(PaymentReservation::ERROR);
        }
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET environment = %s, status = %s, owner_token = %s, lease_expires_at = %s, attempt_id = %s,
                 snapshot_hash = NULL, transfer_intent_id = NULL, transfer_id = NULL, reconcile_after = NULL, error_code = NULL, attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d
               AND (status IN (%s, %s, %s) OR (status = %s AND lease_expires_at < UTC_TIMESTAMP()))",
            $environment,
            PaymentLockStatus::PREPARING,
            $token,
            $lease,
            $attempt_id,
            $order_id,
            PaymentLockStatus::UNCERTAIN,
            PaymentLockStatus::FAILED,
            PaymentLockStatus::RETIRED,
            PaymentLockStatus::PREPARING
        ));
        if (false === $claimed) {
            return new PaymentReservation(PaymentReservation::ERROR);
        }
        if (1 === $claimed) {
            return new PaymentReservation(PaymentReservation::ACQUIRED, $token);
        }
        return new PaymentReservation(PaymentLockStatus::CREATED === $this->status($order_id) ? PaymentReservation::ACTIVE_INTENT : PaymentReservation::BUSY);
    }

    /** Crosses the point after which a crash has an ambiguous remote outcome. */
    public function begin_creation(int $order_id, string $owner_token, string $snapshot_hash): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . ' SET status = %s, snapshot_hash = %s, lease_expires_at = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s',
            PaymentLockStatus::CREATING,
            $snapshot_hash,
            gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
            $order_id,
            PaymentLockStatus::PREPARING,
            $owner_token
        ));
    }

    /** Durably records the created intent ID before any order meta is written. */
    public function mark_created(int $order_id, string $owner_token, string $intent_id): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . ' SET status = %s, transfer_intent_id = %s, owner_token = NULL, lease_expires_at = NULL, reconcile_after = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s',
            PaymentLockStatus::CREATED,
            $intent_id,
            gmdate('Y-m-d H:i:s', time() + self::RECONCILE_FIRST_CHECK_SECONDS),
            $order_id,
            PaymentLockStatus::CREATING,
            $owner_token
        ));
    }

    public function mark_failed(int $order_id, string $owner_token, string $error_code): bool
    {
        return $this->finish($order_id, $owner_token, array(PaymentLockStatus::PREPARING, PaymentLockStatus::CREATING), PaymentLockStatus::FAILED, $error_code);
    }

    public function mark_uncertain(int $order_id, string $owner_token, string $error_code): bool
    {
        return $this->finish($order_id, $owner_token, array(PaymentLockStatus::CREATING), PaymentLockStatus::UNCERTAIN, $error_code);
    }

    /** Retire the active intent so exactly one new attempt may reserve the order. */
    public function retire(int $order_id, string $intent_id): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . ' SET status = %s, owner_token = NULL, lease_expires_at = NULL, reconcile_after = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND transfer_intent_id = %s',
            PaymentLockStatus::RETIRED,
            $order_id,
            PaymentLockStatus::CREATED,
            $intent_id
        ));
        return false !== $updated;
    }

    /**
     * Records the transfer created for the active intent. Idempotent for the same transfer;
     * refuses to overwrite a different transfer or to bind a transfer to another intent.
     */
    public function bind_transfer(int $order_id, string $intent_id, string $transfer_id): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . ' SET transfer_id = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND transfer_intent_id = %s AND (transfer_id IS NULL OR transfer_id = %s)',
            $transfer_id,
            $order_id,
            $intent_id,
            $transfer_id
        ));
        if (false === $updated) {
            return false;
        }
        return $transfer_id === (string) $wpdb->get_var($wpdb->prepare(
            'SELECT transfer_id FROM ' . Installer::locks_table() . ' WHERE order_id = %d AND transfer_intent_id = %s',
            $order_id,
            $intent_id
        ));
    }

    /** @return array{order_id:int, status:string}|null */
    public function find_by_transfer_id(string $transfer_id): ?array
    {
        return $this->find_by('transfer_id', $transfer_id);
    }

    /** @return array{order_id:int, status:string}|null */
    public function find_by_intent_id(string $intent_id): ?array
    {
        return $this->find_by('transfer_intent_id', $intent_id);
    }

    /**
     * Orders whose provider state should be re-read, oldest first. Storage-independent
     * (does not rely on WooCommerce order meta queries, which legacy storage lacks).
     *
     * @return list<int>
     */
    public function due_for_reconciliation(string $environment, int $limit): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT order_id FROM ' . Installer::locks_table() . '
             WHERE environment = %s AND status = %s AND reconcile_after IS NOT NULL AND reconcile_after <= UTC_TIMESTAMP()
             ORDER BY reconcile_after ASC LIMIT %d',
            $environment,
            PaymentLockStatus::CREATED,
            max(1, min(100, $limit))
        ));
        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }

    /** Next reconciliation check for the order; null stops scheduled checks. */
    public function schedule_reconciliation(int $order_id, ?int $delay_seconds): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . ' SET reconcile_after = ' . (null === $delay_seconds ? 'NULL' : '%s') . ', updated_at = UTC_TIMESTAMP() WHERE order_id = %d',
            ...(null === $delay_seconds ? array($order_id) : array(gmdate('Y-m-d H:i:s', time() + $delay_seconds), $order_id))
        ));
    }

    /** @return array{order_id:int, status:string}|null */
    private function find_by(string $column, string $value): ?array
    {
        global $wpdb;
        if ('' === $value || ! in_array($column, array('transfer_id', 'transfer_intent_id'), true)) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT order_id, status FROM ' . Installer::locks_table() . " WHERE {$column} = %s LIMIT 2",
            $value
        ), ARRAY_A);
        if (! is_array($rows) || 1 !== count($rows)) {
            // Zero: unknown. Two: a correlation conflict that must never be guessed.
            return null;
        }
        return array('order_id' => (int) $rows[0]['order_id'], 'status' => (string) $rows[0]['status']);
    }

    /** @return array{status:string, transfer_intent_id:string, snapshot_hash:string, environment:string}|null */
    public function row(int $order_id): ?array
    {
        global $wpdb;
        if (! $this->expire_abandoned($order_id)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT status, transfer_intent_id, snapshot_hash, environment FROM ' . Installer::locks_table() . ' WHERE order_id = %d',
            $order_id
        ), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        return array(
            'status' => (string) ($row['status'] ?? ''),
            'transfer_intent_id' => (string) ($row['transfer_intent_id'] ?? ''),
            'snapshot_hash' => (string) ($row['snapshot_hash'] ?? ''),
            'environment' => (string) ($row['environment'] ?? ''),
        );
    }

    public function status(int $order_id): ?string
    {
        $row = $this->row($order_id);
        return null === $row ? null : $row['status'];
    }

    /**
     * A CREATING lease that expired belongs to a worker that died mid-call.
     * Its outcome is unknown, so it becomes UNCERTAIN (never silently reused).
     * Returns false only on a database error.
     */
    public function expire_abandoned(int $order_id): bool
    {
        global $wpdb;
        return false !== $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . " SET status = %s, owner_token = NULL, lease_expires_at = NULL, error_code = 'lease_expired', updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND lease_expires_at < UTC_TIMESTAMP()",
            PaymentLockStatus::UNCERTAIN,
            $order_id,
            PaymentLockStatus::CREATING
        ));
    }

    /** @param list<string> $from */
    private function finish(int $order_id, string $owner_token, array $from, string $to, string $error_code): bool
    {
        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($from), '%s'));
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::locks_table() . " SET status = %s, error_code = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND owner_token = %s AND status IN ({$placeholders})",
            array_merge(array($to, substr($error_code, 0, 64), $order_id, $owner_token), $from)
        ));
    }
}
