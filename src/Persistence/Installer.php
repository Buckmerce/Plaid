<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Settings\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection of plugin-owned tables must read the live database.

/**
 * Creates and verifies the PayBridge-owned schema. PayBridge never reads,
 * migrates or deletes tables owned by any other plugin.
 *
 * Migrations are forward-only: dbDelta() adds the columns/tables of the current
 * version, schema_is_valid() verifies them, and only then is the version stored.
 */
final class Installer
{
    public const SCHEMA_VERSION = '2';
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
		UNIQUE KEY environment_event (environment,event_id),
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
		KEY payment_state (environment,payment_state)
		) {$charset_collate};";
        $refunds_sql = "CREATE TABLE {$refunds} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		order_id bigint(20) unsigned NOT NULL,
		wc_refund_id bigint(20) unsigned NULL,
		environment varchar(16) NOT NULL,
		account_fp char(16) NULL,
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
		UNIQUE KEY environment_refund (environment,refund_id),
		UNIQUE KEY wc_refund_id (wc_refund_id),
		KEY order_status (order_id,status),
		KEY transfer_id (transfer_id),
		KEY reconcile (environment,reconcile_after),
		KEY status (status)
		) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($events_sql);
        dbDelta($locks_sql);
        dbDelta($refunds_sql);
        if (! self::schema_is_valid()) {
            throw new PersistenceException('PayBridge database schema verification failed.');
        }
        self::backfill_account_fingerprint();
        update_option(self::OPTION, self::SCHEMA_VERSION, true);
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
                'columns' => array('id', 'environment', 'event_id', 'event_type', 'transfer_id', 'order_id', 'event_data', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'error_code', 'created_at', 'updated_at', 'processed_at'),
                'unique' => array('environment_event' => array('environment', 'event_id')),
            ),
            self::locks_table() => array(
                'columns' => array('order_id', 'environment', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'attempt_id', 'snapshot_hash', 'transfer_intent_id', 'transfer_id', 'reconcile_after', 'error_code', 'payment_state', 'account_fp', 'monitor_until', 'created_at', 'updated_at'),
                'unique' => array('PRIMARY' => array('order_id')),
            ),
            self::refunds_table() => array(
                'columns' => array('id', 'order_id', 'wc_refund_id', 'environment', 'account_fp', 'attempt_id', 'transfer_id', 'refund_id', 'idempotency_key', 'amount', 'currency', 'status', 'origin', 'failure_code', 'request_id', 'owner_token', 'lease_expires_at', 'reconcile_after', 'monitor_until', 'last_event_id', 'checks', 'created_at', 'updated_at'),
                'unique' => array(
                    'idempotency_key' => array('idempotency_key'),
                    'environment_refund' => array('environment', 'refund_id'),
                    'wc_refund_id' => array('wc_refund_id'),
                ),
            ),
        );
        foreach ($required as $table => $spec) {
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                return false;
            }
            $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
            if (! is_array($columns) || array() !== array_diff($spec['columns'], $columns)) {
                return false;
            }
            $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
            if (! is_array($indexes)) {
                return false;
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
