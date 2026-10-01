<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Settings\AccountScope;
use PayBridge\Plaid\Settings\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection of plugin-owned tables must read the live database.

/**
 * Creates and verifies the PayBridge-owned schema. PayBridge never reads,
 * migrates or deletes tables owned by any other plugin.
 *
 * Migrations are forward-only and idempotent: dbDelta() adds the columns/tables of the
 * current version, the explicit steps of each version run, schema_is_valid() verifies the
 * result, and only then is the version stored. Running install() twice is harmless.
 *
 * Schema 3 (ADR-0018) scopes Plaid event and refund identities by Plaid account:
 * UNIQUE (environment, account_fp, event_id) and UNIQUE (environment, account_fp, refund_id).
 * Rows written before schema 3 whose account cannot be proven keep their history in the
 * AccountScope::LEGACY scope instead of being attributed to a possibly wrong account.
 */
final class Installer
{
    public const SCHEMA_VERSION = '3';
    public const OPTION = 'paybridge_plaid_schema_version';

    public static function events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'paybridge_plaid_events';
    }

    public static function locks_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'paybridge_plaid_payment_locks';
    }

    public static function refunds_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'paybridge_plaid_refunds';
    }

    /** @return list<string> Every PayBridge-owned table (uninstall cleanup uses the same list). */
    public static function tables(): array
    {
        return array(self::events_table(), self::locks_table(), self::refunds_table());
    }

    public static function activate(): void
    {
        self::install();
    }

    /** @throws PersistenceException */
    public static function install(): void
    {
        global $wpdb;
        $events = self::events_table();
        $locks = self::locks_table();
        $refunds = self::refunds_table();
        $charset_collate = $wpdb->get_charset_collate();
        $events_sql = "CREATE TABLE {$events} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		environment varchar(16) NOT NULL,
		account_fp varchar(16) NOT NULL DEFAULT '',
		event_id bigint(20) unsigned NOT NULL,
		event_type varchar(64) NOT NULL,
		transfer_id varchar(64) NULL,
		order_id bigint(20) unsigned NULL,
		event_data longtext NOT NULL,
		status varchar(20) NOT NULL,
		owner_token char(64) NULL,
		lease_expires_at datetime NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		error_code varchar(64) NULL,
		provider_created_at datetime NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		processed_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY account_event (environment,account_fp,event_id),
		KEY scope_status (environment,account_fp,status,lease_expires_at),
		KEY status_lease (status,lease_expires_at),
		KEY transfer_id (transfer_id),
		KEY order_status (order_id,status),
		KEY created_at (created_at)
		) {$charset_collate};";
        $locks_sql = "CREATE TABLE {$locks} (
		order_id bigint(20) unsigned NOT NULL,
		environment varchar(16) NOT NULL,
		status varchar(16) NOT NULL,
		owner_token char(64) NULL,
		lease_expires_at datetime NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		attempt_id char(32) NULL,
		snapshot_hash char(64) NULL,
		transfer_intent_id varchar(64) NULL,
		transfer_id varchar(64) NULL,
		reconcile_after datetime NULL,
		error_code varchar(64) NULL,
		payment_state varchar(32) NULL,
		account_fp char(16) NULL,
		monitor_until datetime NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (order_id),
		KEY status_lease (status,lease_expires_at),
		KEY transfer_intent_id (transfer_intent_id),
		KEY transfer_id (transfer_id),
		KEY reconcile (environment,status,reconcile_after),
		KEY payment_state (environment,payment_state),
		KEY account_state (environment,account_fp,status)
		) {$charset_collate};";
        $refunds_sql = "CREATE TABLE {$refunds} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		order_id bigint(20) unsigned NOT NULL,
		wc_refund_id bigint(20) unsigned NULL,
		environment varchar(16) NOT NULL,
		account_fp varchar(16) NOT NULL DEFAULT '',
		attempt_id char(32) NOT NULL,
		transfer_id varchar(64) NOT NULL,
		refund_id varchar(64) NULL,
		idempotency_key varchar(50) NOT NULL,
		amount varchar(20) NOT NULL,
		currency char(3) NOT NULL,
		status varchar(20) NOT NULL,
		origin varchar(16) NOT NULL,
		failure_code varchar(64) NULL,
		request_id varchar(64) NULL,
		owner_token char(64) NULL,
		lease_expires_at datetime NULL,
		reconcile_after datetime NULL,
		monitor_until datetime NULL,
		last_event_id bigint(20) unsigned NULL,
		checks smallint(5) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY idempotency_key (idempotency_key),
		UNIQUE KEY account_refund (environment,account_fp,refund_id),
		UNIQUE KEY wc_refund_id (wc_refund_id),
		KEY order_status (order_id,status),
		KEY transfer_id (transfer_id),
		KEY reconcile (environment,reconcile_after),
		KEY account_reconcile (environment,account_fp,reconcile_after),
		KEY status (status)
		) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // One installation at a time: a concurrent request fails closed for itself instead of
        // racing the migration steps; the next request finds the schema current.
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire('schema-install')) {
            throw new PersistenceException('A PayBridge schema installation is already running.');
        }
        try {
            $previous = (string) get_option(self::OPTION, '');
            // Schema 2 → 3: refund rows must carry an account before the column becomes NOT NULL.
            self::prepare_refund_account_column();
            dbDelta($events_sql);
            dbDelta($locks_sql);
            dbDelta($refunds_sql);
            self::migrate_account_scope();
            if (! self::schema_is_valid()) {
                throw new PersistenceException('PayBridge database schema verification failed.');
            }
            self::backfill_account_fingerprint();
            if ('' !== $previous && version_compare($previous, '3', '<')) {
                self::migrate_scoped_options();
            }
            update_option(self::OPTION, self::SCHEMA_VERSION, true);
        } finally {
            $mutex->release();
        }
    }

    public static function schema_is_current(): bool
    {
        return self::SCHEMA_VERSION === get_option(self::OPTION, '');
    }

    /** Verifies every column and index the runtime relies on for idempotency and leasing. */
    public static function schema_is_valid(): bool
    {
        global $wpdb;
        $required = array(
            self::events_table() => array(
                'columns' => array('id', 'environment', 'account_fp', 'event_id', 'event_type', 'transfer_id', 'order_id', 'event_data', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'error_code', 'created_at', 'updated_at', 'processed_at'),
                'unique' => array('account_event' => array('environment', 'account_fp', 'event_id')),
                // An environment-only identity would make account B's event 1 a "duplicate" of account A's.
                'absent' => array('environment_event'),
                'not_null' => array('account_fp'),
            ),
            self::locks_table() => array(
                'columns' => array('order_id', 'environment', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'attempt_id', 'snapshot_hash', 'transfer_intent_id', 'transfer_id', 'reconcile_after', 'error_code', 'payment_state', 'account_fp', 'monitor_until', 'created_at', 'updated_at'),
                'unique' => array('PRIMARY' => array('order_id')),
            ),
            self::refunds_table() => array(
                'columns' => array('id', 'order_id', 'wc_refund_id', 'environment', 'account_fp', 'attempt_id', 'transfer_id', 'refund_id', 'idempotency_key', 'amount', 'currency', 'status', 'origin', 'failure_code', 'request_id', 'owner_token', 'lease_expires_at', 'reconcile_after', 'monitor_until', 'last_event_id', 'checks', 'created_at', 'updated_at'),
                'unique' => array(
                    'idempotency_key' => array('idempotency_key'),
                    'account_refund' => array('environment', 'account_fp', 'refund_id'),
                    'wc_refund_id' => array('wc_refund_id'),
                ),
                'absent' => array('environment_refund'),
                'not_null' => array('account_fp'),
            ),
        );
        foreach ($required as $table => $spec) {
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                return false;
            }
            $columns = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $table), ARRAY_A);
            if (! is_array($columns) || array() !== array_diff($spec['columns'], array_column($columns, 'Field'))) {
                return false;
            }
            $nullable = array_column($columns, 'Null', 'Field');
            foreach ($spec['not_null'] ?? array() as $column) {
                if ('NO' !== ($nullable[$column] ?? '')) {
                    return false;
                }
            }
            $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
            if (! is_array($indexes)) {
                return false;
            }
            $index_names = array_column($indexes, 'Key_name');
            foreach ($spec['absent'] ?? array() as $name) {
                if (in_array($name, $index_names, true)) {
                    return false;
                }
            }
            foreach ($spec['unique'] as $name => $index_columns) {
                $found = array();
                foreach ($indexes as $index) {
                    if ($name === ($index['Key_name'] ?? '') && '0' === (string) ($index['Non_unique'] ?? '1')) {
                        $found[(int) $index['Seq_in_index']] = (string) $index['Column_name'];
                    }
                }
                ksort($found);
                if (array_values($found) !== $index_columns) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Schema 2 created refunds.account_fp as a nullable column. Every refund was written with the
     * configured account, so NULL never occurs in practice; if it does, the row is kept in the
     * legacy scope, and the column is then made NOT NULL so the account-scoped unique key holds.
     */
    private static function prepare_refund_account_column(): void
    {
        global $wpdb;
        $table = self::refunds_table();
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return;
        }
        $column = $wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'account_fp'), ARRAY_A);
        if (! is_array($column) || 'NO' === ($column['Null'] ?? '')) {
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE %i SET account_fp = %s WHERE account_fp IS NULL OR account_fp = ''", $table, AccountScope::LEGACY));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Forward-only migration of a PayBridge-owned table (schema 3).
        if (false === $wpdb->query($wpdb->prepare("ALTER TABLE %i MODIFY account_fp varchar(16) NOT NULL DEFAULT ''", $table))) {
            throw new PersistenceException('The refund account column could not be migrated.');
        }
    }

    /**
     * Schema 2 → 3 for the event table: existing rows are kept in the legacy scope (their account
     * cannot be proven: the schema-2 cursor was shared by every account of the environment), then
     * the environment-only identities are dropped so another account's identical event or refund
     * IDs can never be treated as duplicates. Idempotent.
     */
    private static function migrate_account_scope(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE %i SET account_fp = %s WHERE account_fp = ''", self::events_table(), AccountScope::LEGACY));
        foreach (array(self::events_table() => 'environment_event', self::refunds_table() => 'environment_refund') as $table => $index) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Forward-only migration of a PayBridge-owned table (schema 3).
            if (null !== $exists && false === $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $table, $index))) {
                throw new PersistenceException('An environment-scoped identity index could not be removed.');
            }
        }
    }

    /**
     * Schema 2 kept one event cursor and one payment epoch per environment. The cursor is not
     * transferred to any account (it may have been advanced by several accounts); each account's
     * stream therefore starts at 0 and is re-read idempotently. The environment epoch is the
     * earliest intent of ANY account in that environment, so it is a safe (never later) epoch for
     * every account that has payments there; the legacy options stay for auditing.
     */
    private static function migrate_scoped_options(): void
    {
        global $wpdb;
        foreach (array('sandbox', 'production') as $environment) {
            $epoch = get_option(PaymentEpoch::OPTION_PREFIX . $environment, false);
            if (! is_numeric($epoch) || (int) $epoch <= 0) {
                continue;
            }
            $accounts = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT account_fp FROM %i WHERE environment = %s AND account_fp IS NOT NULL', self::locks_table(), $environment));
            foreach (is_array($accounts) ? $accounts : array() as $account_fp) {
                $scope = new AccountScope($environment, (string) $account_fp);
                if ($scope->is_valid()) {
                    PaymentEpoch::adopt($scope, (int) $epoch);
                }
            }
        }
    }

    /**
     * Schema 1 rows predate the account fingerprint. They were created with the credentials
     * configured at upgrade time, so that account is recorded for them (ADR-0015).
     */
    private static function backfill_account_fingerprint(): void
    {
        global $wpdb;
        $fingerprint = Settings::load()->account_fingerprint();
        if ('' === $fingerprint) {
            return;
        }
        $wpdb->query($wpdb->prepare('UPDATE %i SET account_fp = %s WHERE account_fp IS NULL', self::locks_table(), $fingerprint));
    }
}
