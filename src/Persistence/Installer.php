<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection of plugin-owned tables must read the live database.

/**
 * Creates and verifies the PayBridge-owned schema. PayBridge never reads,
 * migrates or deletes tables owned by any other plugin.
 */
final class Installer
{
    public const SCHEMA_VERSION = '1';
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
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (order_id),
		KEY status_lease (status,lease_expires_at),
		KEY transfer_intent_id (transfer_intent_id),
		KEY transfer_id (transfer_id),
		KEY reconcile (environment,status,reconcile_after)
		) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($events_sql);
        dbDelta($locks_sql);
        if (! self::schema_is_valid()) {
            throw new PersistenceException('PayBridge database schema verification failed.');
        }
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
                'columns' => array('order_id', 'environment', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'attempt_id', 'snapshot_hash', 'transfer_intent_id', 'transfer_id', 'reconcile_after', 'error_code', 'created_at', 'updated_at'),
                'unique' => array('PRIMARY' => array('order_id')),
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
}
