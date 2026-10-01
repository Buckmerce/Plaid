<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Exception;

/** A durable database or order write failed; callers must fail closed. */
class PersistenceException extends BuckmerceException
{
}
