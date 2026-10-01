<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Exception;

/** The current request is not authorized to act on the order. */
class AccessDeniedException extends BuckmerceException
{
}
