<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Exception;

use Buckmerce\Plaid\Payment\ReturnRetryDecision;

/** A new bank debit was refused because the order's transfer was returned. */
final class ReturnedPaymentRetryException extends PaymentException
{
    public function __construct(public readonly ReturnRetryDecision $decision)
    {
        parent::__construct('A returned bank payment cannot be debited again for this order: ' . $decision->outcome);
    }
}
