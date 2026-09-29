<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

/** PayBridge-owned private order meta keys. Written only through WooCommerce order CRUD (HPOS-safe). */
final class OrderMeta
{
    public const ENVIRONMENT = '_pbfp_environment';
    public const PAYMENT_STATE = '_pbfp_payment_state';
    public const PAYMENT_SNAPSHOT = '_pbfp_payment_snapshot';
    public const TRANSFER_INTENT_ID = '_pbfp_transfer_intent_id';
    public const TRANSFER_INTENT_STATUS = '_pbfp_transfer_intent_status';
    public const TRANSFER_ID = '_pbfp_transfer_id';
    public const TRANSFER_STATUS = '_pbfp_transfer_status';
    public const REQUEST_ID = '_pbfp_request_id';
    public const LAST_SYNC_AT = '_pbfp_last_sync_at';
    public const FAILURE_CODE = '_pbfp_failure_code';
    public const LAST_EVENT_ID = '_pbfp_last_event_id';
    public const RETURN_CODE = '_pbfp_return_code';
    public const MANUAL_REVIEW_REASON = '_pbfp_manual_review_reason';
    /** Latest expiration (UTC ISO-8601) of any Link token issued for the active Transfer Intent. */
    public const LINK_TOKEN_EXPIRES_AT = '_pbfp_link_token_expires_at';
    /** Audit trail of Transfer Intents that were retired and replaced by a new attempt. */
    public const RETIRED_ATTEMPTS = '_pbfp_retired_attempts';

    private function __construct()
    {
    }
}
