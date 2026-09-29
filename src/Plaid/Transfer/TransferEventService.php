<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Transfer;

use PayBridge\Plaid\Plaid\Client\PlaidClientInterface;
use PayBridge\Plaid\Plaid\DTO\TransferEvent;
use PayBridge\Plaid\Plaid\DTO\TransferEventPage;
use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;

/** Plaid /transfer/event/sync (docs/api/api/products/transfer/reading-transfers.md#transfereventsync). */
final class TransferEventService
{
    public const PAGE_SIZE = 100;

    public function __construct(private readonly PlaidClientInterface $client)
    {
    }

    public function sync(string $after_id, int $count = self::PAGE_SIZE): TransferEventPage
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $after_id)) {
            throw new \InvalidArgumentException('after_id must be an unsigned integer string.');
        }
        // after_id is an unsigned 64-bit integer; values within PHP_INT_MAX are sent as JSON numbers.
        $after = (strlen($after_id) < 19 || strcmp($after_id, (string) PHP_INT_MAX) <= 0) ? (int) $after_id : $after_id;
        $response = $this->client->post('/transfer/event/sync', array('after_id' => $after, 'count' => max(1, min(500, $count))));
        $events = array();
        foreach ($response->list('transfer_events') as $item) {
            if (! is_array($item)) {
                throw new PlaidMalformedResponseException('Plaid transfer event is not an object.', $response->request_id);
            }
            $events[] = TransferEvent::from_array($item, $response->request_id);
        }
        return new TransferEventPage($events, true === ($response->data['has_more'] ?? false), $response->request_id);
    }
}
