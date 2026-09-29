<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Persistence;

use PayBridge\Plaid\Exception\PersistenceException;
use PayBridge\Plaid\Support\Decimal;

/**
 * Per-environment /transfer/event/sync position. It is advanced only after
 * every event up to the new position is durably recorded in the event store.
 */
final class EventCursor
{
    private const OPTION_PREFIX = 'paybridge_plaid_event_cursor_';

    public function get(string $environment): string
    {
        $value = get_option(self::OPTION_PREFIX . $environment, '0');
        $value = is_scalar($value) ? (string) $value : '0';
        return preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $value) ? $value : '0';
    }

    /** @throws PersistenceException */
    public function advance(string $environment, string $event_id): void
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $event_id)) {
            throw new PersistenceException('Invalid transfer event cursor.');
        }
        $current = $this->get($environment);
        if (Decimal::compare($event_id, $current) <= 0) {
            return;
        }
        update_option(self::OPTION_PREFIX . $environment, $event_id, false);
        if ($this->get($environment) !== $event_id) {
            throw new PersistenceException('Transfer event cursor could not be persisted.');
        }
    }

    /** @return list<string> */
    public static function option_names(): array
    {
        return array(self::OPTION_PREFIX . 'sandbox', self::OPTION_PREFIX . 'production');
    }
}
