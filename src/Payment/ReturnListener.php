<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/**
 * Notified once when the current attempt's ACH debit is returned. The refund module uses
 * it to detect refunds issued for a payment that was then reversed (ADR-0016).
 */
interface ReturnListener
{
    /**
     * @return array{count:int, amount:string} Refunds that left (or may leave) the merchant for the returned transfer.
     */
    public function on_payment_returned(\WC_Order $order, string $transfer_id): array;
}
