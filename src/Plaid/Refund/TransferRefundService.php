<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Refund;

use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\DTO\TransferRefund;
use Buckmerce\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use Buckmerce\Plaid\Support\Money;

/**
 * Plaid /transfer/refund/create, /transfer/refund/get and /transfer/refund/cancel
 * (https://plaid.com/docs/api/products/transfer/refunds/).
 */
final class TransferRefundService
{
    /** Plaid limit for idempotency_key. */
    public const IDEMPOTENCY_KEY_MAX = 50;

    public function __construct(private readonly PlaidClientInterface $client)
    {
    }

    /**
     * Command: creates a refund. Plaid guarantees that retrying with the same idempotency
     * key creates at most one refund. The response is validated against the request.
     */
    public function create(string $transfer_id, string $amount, string $idempotency_key): TransferRefund
    {
        if ('' === $idempotency_key || strlen($idempotency_key) > self::IDEMPOTENCY_KEY_MAX) {
            throw new \InvalidArgumentException('Invalid refund idempotency key.');
        }
        $response = $this->client->post('/transfer/refund/create', array(
            'transfer_id' => $transfer_id,
            'amount' => $amount,
            'idempotency_key' => $idempotency_key,
        ));
        $refund = TransferRefund::from_array($response->object('refund'), $response->request_id);
        if ($refund->transfer_id !== $transfer_id || ! Money::same_amount($refund->amount, $amount)) {
            throw new PlaidMalformedResponseException('Plaid returned a refund that does not match the request.', esc_html($response->request_id));
        }
        return $refund;
    }

    /** Query: authoritative refund state. Safe to repeat. */
    public function get(string $refund_id): TransferRefund
    {
        $response = $this->client->post('/transfer/refund/get', array('refund_id' => $refund_id));
        return TransferRefund::from_array($response->object('refund'), $response->request_id);
    }

    /** Command: cancels a refund that has not been submitted to the network yet. Returns the request ID. */
    public function cancel(string $refund_id): string
    {
        return $this->client->post('/transfer/refund/cancel', array('refund_id' => $refund_id))->request_id;
    }
}
