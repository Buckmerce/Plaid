# ADR-0007: Ambiguous Intent Creation and Plaid Ledger Compatibility

**Status:** Accepted

## Context

Two edge cases emerged during Plaid sandbox integration regarding Transfer Intent creation (`/transfer/intent/create`):

1. **Ambiguous outcomes (Timeouts/5xx):** Plaid Transfer Intent creation does not support merchant-provided idempotency keys. If the HTTP request times out (e.g. 504 Gateway Timeout) or Plaid returns an `API_ERROR` (500), the plugin cannot know whether the Transfer Intent was created and funded, or if it failed before creation.
2. **Plaid Ledger constraint:** Task 14 originally required `funding_account_id` to be explicitly passed during `/transfer/intent/create` to identify the merchant's receiving account. However, for Plaid accounts with Plaid Ledger enabled, Plaid's API rejects requests containing `funding_account_id` with an `INVALID_FIELD` error.

## Decision

### 1. Handling Ambiguous Intent Creation
When `PlaidClient` throws a `PlaidApiException` that is ambiguous (`http_status >= 500` or `error_type === 'API_ERROR'`), or a network timeout occurs:
- The payment state transitions to `intent_uncertain`.
- The database lock marks the attempt as uncertain.
- If the customer retries checkout, the uncertain attempt is safely abandoned (as no frontend client received a Link token for it, it cannot be authorized by the customer). A new attempt is created.

### 2. Making `funding_account_id` Optional
- The `funding_account_id` field in the admin settings is optional.
- The plugin will only include `funding_account_id` in the API payload if it has been explicitly provided in the settings. Otherwise, the field is omitted.
- This allows merchants using Plaid Ledger to omit the ID and successfully create Transfer Intents.

## Consequences

- **Safety:** An ambiguous intent cannot lead to double charging, because an intent requires the customer to authorize it via Plaid Link. If the intent ID was lost in a timeout, the customer never sees the Link UI for it. The only risk is a stale pending intent at Plaid, which expires automatically.
- **Ledger compatibility:** Plaid Ledger merchants can operate without `INVALID_FIELD` errors. Non-Ledger merchants must ensure they provide a valid `funding_account_id` in the gateway settings, otherwise Plaid will reject the request.
- **Traceability:** The `intent_uncertain` state makes network failures explicit in the order history rather than blending them into generic `failed` states.
