<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\DTO;

final class TransferEventPage
{
    /** @param list<TransferEvent> $events */
    public function __construct(public readonly array $events, public readonly bool $has_more, public readonly string $request_id)
    {
    }
}
