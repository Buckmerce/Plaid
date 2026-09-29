<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Transfer;

use PayBridge\Plaid\Plaid\Client\PlaidClientInterface;
use PayBridge\Plaid\Plaid\DTO\Transfer;

/** Plaid /transfer/get (docs/api/api/products/transfer/reading-transfers.md). */
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
}
