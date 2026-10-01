<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Exception;

/** A requested payment state change is not allowed by the state machine. */
class InvalidPaymentStateTransition extends PaymentException
{
}
