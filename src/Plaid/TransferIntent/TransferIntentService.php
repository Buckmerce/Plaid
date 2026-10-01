<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\TransferIntent;

use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\DTO\TransferIntent;

/** Plaid /transfer/intent/create and /transfer/intent/get (https://plaid.com/docs/api/products/transfer/account-linking/). */
final class TransferIntentService
{
    public function __construct(private readonly PlaidClientInterface $client)
    {
    }

    /**
     * Command: creates a Transfer Intent. Callers must hold the payment reservation.
     *
     * @param array<string, mixed> $request
     */
    public function create(array $request): TransferIntent
    {
        $response = $this->client->post('/transfer/intent/create', $request);
        return TransferIntent::from_array($response->object('transfer_intent'), $response->request_id);
    }

    /** Query: authoritative Transfer Intent state. Safe to repeat. */
    public function get(string $transfer_intent_id): TransferIntent
    {
        $response = $this->client->post('/transfer/intent/get', array('transfer_intent_id' => $transfer_intent_id));
        return TransferIntent::from_array($response->object('transfer_intent'), $response->request_id);
    }
}
