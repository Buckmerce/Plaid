# Plaid Integration Design

## 1. Product choice

MVP uses **Plaid Transfer UI** for one-time bank payments.

Why:

- integrates authorization UI with Transfer;
- supports one-time payment authorization;
- Plaid manages Proof of Authorization for the Transfer UI flow;
- reduces custom ACH authorization surface area.

Transfer UI is intentionally not treated as a future recurring-payment solution.

## 2. Environment model

Supported plugin environments:

- Sandbox;
- Production.

Environment and Plaid account are part of payment identity. A payment created in one environment or with one Plaid account must never be queried with another's credentials, and the transfer-event stream, its cursor, the payment epoch and refund identities are kept per environment + account (ADR-0015, ADR-0018).

Production requires HTTPS.

## 3. Endpoints used (1.0)

- `/transfer/intent/create`, `/transfer/intent/get`
- `/link/token/create` (with the Link customization; required and always sent in both environments, ADR-0020)
- `/transfer/get` (status, return windows, cancellable, refunds), `/transfer/cancel` (merchant action)
- `/transfer/event/sync` (payment and refund events)
- `/transfer/refund/create`, `/transfer/refund/get`, `/transfer/refund/cancel`
- `/transfer/configuration/get`, `/transfer/ledger/get` (connection test)
- `/webhook_verification_key/get`
- Sandbox only: `/sandbox/transfer/fire_webhook`, `/sandbox/transfer/refund/simulate`,
  `/sandbox/transfer/simulate` (blocked in Production by the client)

The authoritative field-level contract is `docs/api/PLAID_TRANSFER.md`.

Before implementation, confirm exact schemas in official Plaid docs linked from `api/PLAID_TRANSFER.md`.

## 4. API client rules

Centralize:

- base URL;
- `PLAID-CLIENT-ID` / `PLAID-SECRET` authentication;
- JSON encoding;
- timeout;
- response parsing;
- request ID capture;
- typed Plaid errors;
- safe redacted logging.

No controller should construct Plaid HTTP requests directly.

## 5. Transfer Intent metadata

Where Plaid supports application metadata/reference fields, use non-sensitive order/attempt correlation. Never put secrets or unnecessary PII in provider metadata.

Implemented (`TransferIntentRequest::build()`): `metadata` contains only ASCII strings
`pbfp_order_id`, `pbfp_attempt_id`, `pbfp_environment` and `pbfp_site` (a 16-hex HMAC
store marker, ADR-0010). The request also sends `mode: PAYMENT`, `amount` as a decimal
string, `description` (the merchant's bank statement description, default `PAYMENT`,
≤ 10 characters), `ach_class: web` (fixed, ADR-0013), `network`, `iso_currency_code: USD`,
`user.legal_name` (billing first + last name, required) and `user.email_address`.
`funding_account_id` is sent only when configured, because Plaid rejects it for accounts
with Plaid Ledger (ADR-0007).

## 6. Link token rules

The Link token endpoint derives all sensitive/authoritative fields from stored server state.

Frontend cannot select:

- transfer intent ID;
- amount;
- funding account;
- environment.

## 7. Provider response validation

Validate presence/type of required response fields. Treat malformed success responses as provider errors rather than indexing blindly into arrays.

Capture Plaid `request_id` for support correlation.

## 8. Retry policy

Retry only operations known to be safe to repeat and only for transient errors.

Creation operations with ambiguous outcomes require idempotency/provider lookup rather than blind retry.

## 9. Future features

Refunds shipped in 1.0 (ADR-0016). Post-v1 capabilities should be separate increments:

- recurring/subscriptions where product compatibility permits;
- saved bank accounts;
- multiple simultaneously configured Plaid accounts (to allow account switches without waiting
  for return windows, ADR-0015);
- Plaid-compliant reprocessing of R01/R09 returns ("Retry 1"/"Retry 2") through `/transfer/create`
  (Transfer UI has no retry marking, ADR-0019; `ReturnRetryPolicy` already encodes the rules);
- other Transfer rails;
- Europe through Plaid Payments, not by forcing European semantics into US Transfer code;
- Platform architecture only after Plaid commercial/technical approval.
