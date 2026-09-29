<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Exception;

/** Another worker currently owns this order's payment attempt; no remote call was made. */
class PaymentAttemptBusyException extends PaymentException
{
}
