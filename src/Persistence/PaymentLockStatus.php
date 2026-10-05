<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Persistence;

/** Durable lifecycle of one order's Transfer Intent creation fence. */
final class PaymentLockStatus
{
    /** Reserved; no Plaid call can have happened yet. */
    public const PREPARING = 'preparing';
    /** /transfer/intent/create may have reached Plaid. */
    public const CREATING = 'creating';
    /** Plaid returned an intent and its ID is durably recorded in this row. */
    public const CREATED = 'created';
    /** Outcome unknown. Safe to replace because an unknown intent can never receive a Link token. */
    public const UNCERTAIN = 'uncertain';
    /** Plaid definitively rejected the create request. */
    public const FAILED = 'failed';
    /** The active intent was retired (failed/unusable) so a new attempt may start. */
    public const RETIRED = 'retired';

    /** Statuses from which a new reservation may be claimed. */
    public const CLAIMABLE = array(self::UNCERTAIN, self::FAILED, self::RETIRED);

    private function __construct()
    {
    }
}
