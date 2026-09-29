<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;

/**
 * Connection-bound advisory lock (MySQL GET_LOCK). Unlike a TTL lease, a slow
 * but live worker cannot lose it to a timer; it is released automatically if
 * the worker's database connection dies.
 */
final class DatabaseMutex
{
    /** @var array<string, bool> Reject re-entrant acquisition on this connection. */
    private static array $held = array();
    private string $name = '';

    /** @throws PersistenceException when the database cannot provide a lock at all (fail closed). */
    public function acquire(string $resource): bool
    {
        global $wpdb;
        $database = $wpdb->get_var('SELECT DATABASE()');
        if (! is_string($database) || '' === $database) {
            throw new PersistenceException('Database context unavailable.');
        }
        // MySQL lock names are limited to 64 characters; hash keeps them short and site-scoped.
        $name = 'pbfp_' . substr(hash('sha256', $database . ':' . $wpdb->prefix . ':paybridge-plaid:' . $resource), 0, 48);
        if (isset(self::$held[$name])) {
            return false;
        }
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if (null === $result || '' !== $wpdb->last_error) {
            throw new PersistenceException('Database mutex unavailable.');
        }
        if ('1' !== (string) $result) {
            return false;
        }
        self::$held[$name] = true;
        $this->name = $name;
        return true;
    }

    /** @throws PersistenceException */
    public function assert_owned(): void
    {
        global $wpdb;
        if ('' === $this->name || '1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name))) {
            throw new PersistenceException('Database mutex ownership lost.');
        }
    }

    public function release(): void
    {
        global $wpdb;
        if ('' === $this->name) {
            return;
        }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
        unset(self::$held[$this->name]);
        $this->name = '';
    }

    /**
     * Run $callback while holding the lock.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     * @throws \PayBridge\Plaid\Exception\PaymentAttemptBusyException
     */
    public static function with(string $resource, callable $callback)
    {
        $mutex = new self();
        if (! $mutex->acquire($resource)) {
            throw new \PayBridge\Plaid\Exception\PaymentAttemptBusyException('Another request is processing this payment. Please retry shortly.');
        }
        try {
            return $callback($mutex);
        } finally {
            $mutex->release();
        }
    }

    public static function payment_resource(int $order_id): string
    {
        return 'payment:' . $order_id;
    }
}
