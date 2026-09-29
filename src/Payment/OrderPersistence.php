<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use PayBridge\Plaid\Exception\PersistenceException;

/**
 * Durable order writes for payment-critical metadata.
 *
 * WC_Abstract_Order::save() catches and logs exceptions instead of rethrowing
 * them, so a failed write is indistinguishable from a successful one. Every
 * payment-critical save is therefore verified against the order data store
 * (HPOS or posts), bypassing object caches, and fails closed on mismatch.
 */
final class OrderPersistence
{
    /**
     * @param array<string, string|null> $expected meta key => value (null/'' = absent)
     * @throws PersistenceException
     */
    public static function save(\WC_Order $order, array $expected): void
    {
        $order->save();
        if (array() === $expected) {
            return;
        }
        $stored = self::stored_meta($order);
        foreach ($expected as $key => $value) {
            $actual = $stored[$key] ?? null;
            $want = (null === $value || '' === $value) ? null : $value;
            $have = (null === $actual || '' === $actual) ? null : $actual;
            if ($want !== $have) {
                throw new PersistenceException(sprintf('Order %d metadata %s was not persisted.', $order->get_id(), $key));
            }
        }
    }

    /** @return array<string, string> */
    private static function stored_meta(\WC_Order $order): array
    {
        if ($order->get_id() < 1) {
            return array();
        }
        $data_store = $order->get_data_store();
        if (! is_object($data_store) || ! is_callable(array($data_store, 'read_meta'))) {
            throw new PersistenceException('Order metadata cannot be verified.');
        }
        $probe = $order;
        $rows = $data_store->read_meta($probe);
        $meta = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $key = is_object($row) ? ($row->meta_key ?? null) : null;
            $value = is_object($row) ? ($row->meta_value ?? null) : null;
            if (! is_string($key) || ! str_starts_with($key, '_pbfp_')) {
                continue;
            }
            $value = is_string($value) ? maybe_unserialize($value) : $value;
            if (is_scalar($value)) {
                $meta[$key] = (string) $value;
            }
        }
        return $meta;
    }
}
