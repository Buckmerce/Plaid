<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Persistence;

use Buckmerce\Plaid\Exception\PersistenceException;
use Buckmerce\Plaid\Settings\AccountScope;
use Buckmerce\Plaid\Support\Decimal;

/**
 * /transfer/event/sync position of one Plaid event stream (environment + account, ADR-0018).
 * It is advanced only after every event up to the new position is durably recorded in the
 * event store. A Plaid account that never synced here starts at 0; another account's cursor
 * is never used, and a previous account's cursor is kept for auditing.
 */
final class EventCursor
{
    /** Schema-2 per-environment options (kept for auditing) use this prefix + environment. */
    public const OPTION_PREFIX = 'buckmerce_plaid_event_cursor_';

    public static function option_name(AccountScope $scope): string
    {
        return self::OPTION_PREFIX . $scope->key();
    }

    public function get(AccountScope $scope): string
    {
        if (! $scope->is_valid()) {
            return '0';
        }
        $value = get_option(self::option_name($scope), '0');
        $value = is_scalar($value) ? (string) $value : '0';
        return preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $value) ? $value : '0';
    }

    /** @throws PersistenceException */
    public function advance(AccountScope $scope, string $event_id): void
    {
        if (! $scope->is_valid()) {
            throw new PersistenceException('Transfer event cursor needs a Plaid account scope.');
        }
        if (! preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $event_id)) {
            throw new PersistenceException('Invalid transfer event cursor.');
        }
        $current = $this->get($scope);
        if (Decimal::compare($event_id, $current) <= 0) {
            return;
        }
        update_option(self::option_name($scope), $event_id, false);
        if ($this->get($scope) !== $event_id) {
            throw new PersistenceException('Transfer event cursor could not be persisted.');
        }
    }
}
