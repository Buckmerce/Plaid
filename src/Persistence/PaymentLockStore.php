<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Persistence;

use Buckmerce\Plaid\Settings\AccountScope;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; compare-and-set locking must bypass object caches.

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

    public function acquire(int $order_id, string $environment, string $attempt_id, string $account_fp = ''): PaymentReservation
    {
        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $lease = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        // INSERT IGNORE turns only the expected primary-key collision into a
        // deterministic branch; a real DB error returns false and fails closed.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (order_id, environment, status, owner_token, lease_expires_at, attempts, attempt_id, account_fp, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, 1, %s, NULLIF(%s, ''), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            Installer::locks_table(),
            $order_id,
            $environment,
            PaymentLockStatus::PREPARING,
            $token,
            $lease,
            $attempt_id,
            $account_fp
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
            "UPDATE %i
             SET environment = %s, status = %s, owner_token = %s, lease_expires_at = %s, attempt_id = %s, account_fp = NULLIF(%s, ''),
                 snapshot_hash = NULL, transfer_intent_id = NULL, transfer_id = NULL, reconcile_after = NULL, monitor_until = NULL, payment_state = NULL,
                 error_code = NULL, attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d
               AND (status IN (%s, %s, %s) OR (status = %s AND lease_expires_at < UTC_TIMESTAMP()))",
            Installer::locks_table(),
            $environment,
            PaymentLockStatus::PREPARING,
            $token,
            $lease,
            $attempt_id,
            $account_fp,
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
            'UPDATE %i SET status = %s, snapshot_hash = %s, lease_expires_at = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s',
            Installer::locks_table(),
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
            'UPDATE %i SET status = %s, transfer_intent_id = %s, owner_token = NULL, lease_expires_at = NULL, reconcile_after = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s',
            Installer::locks_table(),
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
        return $this->finish($order_id, $owner_token, array(PaymentLockStatus::CREATING, PaymentLockStatus::CREATING), PaymentLockStatus::UNCERTAIN, $error_code);
    }

    /** Retire the active intent so exactly one new attempt may reserve the order. */
    public function retire(int $order_id, string $intent_id): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET status = %s, owner_token = NULL, lease_expires_at = NULL, reconcile_after = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND transfer_intent_id = %s',
            Installer::locks_table(),
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
            'UPDATE %i SET transfer_id = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND transfer_intent_id = %s AND (transfer_id IS NULL OR transfer_id = %s)',
            Installer::locks_table(),
            $transfer_id,
            $order_id,
            $intent_id,
            $transfer_id
        ));
        if (false === $updated) {
            return false;
        }
        return $transfer_id === (string) $wpdb->get_var($wpdb->prepare(
            'SELECT transfer_id FROM %i WHERE order_id = %d AND transfer_intent_id = %s',
            Installer::locks_table(),
            $order_id,
            $intent_id
        ));
    }

    /**
     * The order whose current attempt used this transfer with this Plaid account (ADR-0018).
     * Plaid does not document identifiers as unique across clients, so a lookup never crosses
     * accounts; rows written before the account fingerprint existed (NULL) still match.
     *
     * @return array{order_id:int, status:string}|null
     */
    public function find_by_transfer_id(string $transfer_id, AccountScope $scope): ?array
    {
        return $this->find_by('transfer_id', $transfer_id, $scope);
    }

    /** @return array{order_id:int, status:string}|null */
    public function find_by_intent_id(string $intent_id, AccountScope $scope): ?array
    {
        return $this->find_by('transfer_intent_id', $intent_id, $scope);
    }

    /**
     * Orders whose provider state should be re-read, oldest first. Storage-independent
     * (does not rely on WooCommerce order meta queries, which legacy storage lacks).
     *
     * @return list<int>
     */
    public function due_for_reconciliation(string $environment, int $limit, string $account_fp = ''): array
    {
        global $wpdb;
        // Only payments of the configured Plaid account can be read with its credentials (ADR-0015).
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM %i
             WHERE environment = %s AND status = %s AND reconcile_after IS NOT NULL AND reconcile_after <= UTC_TIMESTAMP()
               AND (account_fp IS NULL OR %s = '' OR account_fp = %s)
             ORDER BY reconcile_after ASC LIMIT %d",
            Installer::locks_table(),
            $environment,
            PaymentLockStatus::CREATED,
            $account_fp,
            $account_fp,
            max(1, min(100, $limit))
        ));
        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }

    /** Next reconciliation check for the order; null stops scheduled checks. */
    public function schedule_reconciliation(int $order_id, ?int $delay_seconds): void
    {
        global $wpdb;
        if (null === $delay_seconds) {
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET reconcile_after = NULL, updated_at = UTC_TIMESTAMP() WHERE order_id = %d',
                Installer::locks_table(),
                $order_id
            ));
            return;
        }
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET reconcile_after = %s, updated_at = UTC_TIMESTAMP() WHERE order_id = %d',
            Installer::locks_table(),
            gmdate('Y-m-d H:i:s', time() + $delay_seconds),
            $order_id
        ));
    }

    /**
     * Projection of the order's payment state and monitoring plan into the index, so that
     * monitoring, diagnostics and the account-change guard never scan WooCommerce orders.
     * Only the row of the order's current attempt is updated.
     */
    public function record_monitoring(int $order_id, string $attempt_id, string $payment_state, ?int $next_check_at, ?int $monitor_until): bool
    {
        global $wpdb;
        // Timestamps are formatted as UTC in PHP: FROM_UNIXTIME() would use the session time zone.
        return false !== $wpdb->query($wpdb->prepare(
            "UPDATE %i SET payment_state = NULLIF(%s, ''), reconcile_after = NULLIF(%s, ''), monitor_until = NULLIF(%s, ''), updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND attempt_id = %s AND status = %s",
            Installer::locks_table(),
            $payment_state,
            null === $next_check_at ? '' : gmdate('Y-m-d H:i:s', $next_check_at),
            null === $monitor_until ? '' : gmdate('Y-m-d H:i:s', $monitor_until),
            $order_id,
            $attempt_id,
            PaymentLockStatus::CREATED
        ));
    }

    /**
     * Current attempts whose provider state can still change, per Plaid account (ADR-0015):
     * money in flight, a pending authorization, or an open ACH return window, including
     * transfers under manual review (reconcile_after is kept until the window closes).
     *
     * @return array{count:int, oldest:string}
     */
    public function monitored(string $environment, string $account_fp = ''): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, MIN(created_at) AS oldest FROM %i
             WHERE environment = %s AND status = %s
               AND (%s = '' OR account_fp IS NULL OR account_fp = %s)
               AND (
                    reconcile_after IS NOT NULL
                 OR payment_state IN ('intent_created', 'intent_pending', 'transfer_created', 'pending', 'posted', 'settled')
               )",
            Installer::locks_table(),
            $environment,
            PaymentLockStatus::CREATED,
            $account_fp,
            $account_fp
        ), ARRAY_A);
        return array('count' => is_array($row) ? (int) $row['total'] : 0, 'oldest' => is_array($row) ? (string) ($row['oldest'] ?? '') : '');
    }

    /** @return array<string, int> Current attempts per payment state in one environment. */
    public function count_by_state(string $environment): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COALESCE(payment_state, %s) AS state, COUNT(*) AS total FROM %i WHERE environment = %s GROUP BY state',
            'unknown',
            Installer::locks_table(),
            $environment
        ), ARRAY_A);
        $counts = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $counts[(string) $row['state']] = (int) $row['total'];
        }
        return $counts;
    }

    public function count_by_lock_status(string $status): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', Installer::locks_table(), $status));
    }

    /** Rows of another Plaid account in this environment: they cannot be monitored with the current credentials. */
    public function count_other_account(string $environment, string $account_fp): int
    {
        global $wpdb;
        if ('' === $account_fp) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE environment = %s AND status = %s AND account_fp IS NOT NULL AND account_fp <> %s AND reconcile_after IS NOT NULL',
            Installer::locks_table(),
            $environment,
            PaymentLockStatus::CREATED,
            $account_fp
        ));
    }

    /** @return list<int> Created rows whose index projection predates schema 2 (bounded backfill). */
    public function missing_projection(int $limit): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT order_id FROM %i WHERE status = %s AND payment_state IS NULL ORDER BY order_id ASC LIMIT %d',
            Installer::locks_table(),
            PaymentLockStatus::CREATED,
            max(1, min(100, $limit))
        ));
        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }

    /** @return array{order_id:int, status:string}|null */
    private function find_by(string $column, string $value, AccountScope $scope): ?array
    {
        global $wpdb;
        if ('' === $value || ! $scope->is_valid() || ! in_array($column, array('transfer_id', 'transfer_intent_id'), true)) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT order_id, status FROM %i WHERE %i = %s AND environment = %s AND (account_fp = %s OR account_fp IS NULL) LIMIT 2',
            Installer::locks_table(),
            $column,
            $value,
            $scope->environment,
            $scope->account_fp
        ), ARRAY_A);
        if (! is_array($rows) || 1 !== count($rows)) {
            // Zero: unknown. Two: a correlation conflict that must never be guessed.
            return null;
        }
        return array('order_id' => (int) $rows[0]['order_id'], 'status' => (string) $rows[0]['status']);
    }

    /** @return array{status:string, transfer_intent_id:string, snapshot_hash:string, environment:string, account_fp:string, payment_state:string, reconcile_after:string, monitor_until:string}|null */
    public function row(int $order_id): ?array
    {
        global $wpdb;
        if (! $this->expire_abandoned($order_id)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT status, transfer_intent_id, snapshot_hash, environment, account_fp, payment_state, reconcile_after, monitor_until FROM %i WHERE order_id = %d',
            Installer::locks_table(),
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
            'account_fp' => (string) ($row['account_fp'] ?? ''),
            'payment_state' => (string) ($row['payment_state'] ?? ''),
            'reconcile_after' => (string) ($row['reconcile_after'] ?? ''),
            'monitor_until' => (string) ($row['monitor_until'] ?? ''),
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
            "UPDATE %i SET status = %s, owner_token = NULL, lease_expires_at = NULL, error_code = 'lease_expired', updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND lease_expires_at < UTC_TIMESTAMP()",
            Installer::locks_table(),
            PaymentLockStatus::UNCERTAIN,
            $order_id,
            PaymentLockStatus::CREATING
        ));
    }

    /** @param array{string, string} $from The two statuses the owner may finish from (repeat one to allow a single status). */
    private function finish(int $order_id, string $owner_token, array $from, string $to, string $error_code): bool
    {
        global $wpdb;
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE %i SET status = %s, error_code = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND owner_token = %s AND status IN (%s, %s)',
            Installer::locks_table(),
            $to,
            substr($error_code, 0, 64),
            $order_id,
            $owner_token,
            $from[0],
            $from[1]
        ));
    }
}
