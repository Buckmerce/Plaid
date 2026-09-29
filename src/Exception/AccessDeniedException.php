<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Exception;

/** The current request is not authorized to act on the order. */
class AccessDeniedException extends PayBridgeException
{
}
