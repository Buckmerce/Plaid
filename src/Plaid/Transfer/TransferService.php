<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Transfer;

use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\DTO\Transfer;

/** Plaid /transfer/get (https://plaid.com/docs/api/products/transfer/reading-transfers/). */
final class TransferService
{
    public function __construct(private readonly PlaidClientInterface $client)
    {
    }

    public function get(string $transfer_id): Transfer
    {
        $response = $this->client->post('/transfer/get', array('transfer_id' => $transfer_id));
        return Transfer::from_array($response->object('transfer'), $response->request_id);
    }

    /**
     * Command: cancels a transfer Plaid still reports as cancellable. Returns the request ID.
     * Plaid answers TRANSFER_NOT_CANCELLABLE once the transfer was sent to the network.
     */
    public function cancel(string $transfer_id): string
    {
        return $this->client->post('/transfer/cancel', array('transfer_id' => $transfer_id))->request_id;
    }
}
