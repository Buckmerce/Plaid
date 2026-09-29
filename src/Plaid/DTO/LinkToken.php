<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\DTO;

/** Short-lived Link token. The token itself is only ever returned to the authorized payer's browser. */
final class LinkToken
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly string $expiration,
        public readonly string $request_id
    ) {
    }
}
