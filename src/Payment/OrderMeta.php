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
    /** Audit trail of payment attempts that were retired and replaced by a new attempt. */
    public const RETIRED_ATTEMPTS = '_pbfp_retired_attempts';
    /** Merchant alert/email for the current attempt's ACH return was already sent. */
    public const RETURN_ALERTED = '_pbfp_return_alerted';

    /** Non-secret identity of the Plaid account that created the current attempt (ADR-0015). */
    public const ACCOUNT_FINGERPRINT = '_pbfp_account_fingerprint';
    /** Provider timestamps (UTC ISO-8601) of the current transfer. */
    public const TRANSFER_CREATED_AT = '_pbfp_transfer_created_at';
    public const SETTLED_AT = '_pbfp_settled_at';
    public const FUNDS_AVAILABLE_AT = '_pbfp_funds_available_at';
    public const RETURNED_AT = '_pbfp_returned_at';
    /** Return windows reported by /transfer/get (YYYY-MM-DD) and the expected funds date. */
    public const STANDARD_RETURN_WINDOW = '_pbfp_standard_return_window';
    public const UNAUTHORIZED_RETURN_WINDOW = '_pbfp_unauthorized_return_window';
    public const EXPECTED_FUNDS_AVAILABLE_DATE = '_pbfp_expected_funds_available_date';
    /** 'yes' when the last /transfer/get reported the transfer as cancellable. */
    public const TRANSFER_CANCELLABLE = '_pbfp_transfer_cancellable';
    /** Sanitized provider description of a failure or return (never raw payloads). */
    public const FAILURE_DESCRIPTION = '_pbfp_failure_description';
    /** Manual review was resolved by a merchant (who, when, decision) — kept for audit. */
    public const MANUAL_REVIEW_RESOLUTION = '_pbfp_manual_review_resolution';

    private function __construct()
    {
    }
}
