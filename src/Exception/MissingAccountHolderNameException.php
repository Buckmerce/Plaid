<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Exception;

/** The order lacks the account holder's legal name, which Plaid requires for a Transfer Intent. */
final class MissingAccountHolderNameException extends PaymentException
{
}
