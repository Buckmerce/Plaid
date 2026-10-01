# Plaid Transfer API — PayBridge Integration Contract

> This document describes **which** Plaid endpoints PayBridge calls, how, and under what
> constraints. It is not a substitute for official Plaid documentation.
>
> **Official source:** <https://plaid.com/docs/transfer/> and <https://plaid.com/docs/api/products/transfer/>
> **Local copy (optional, git-ignored):** `bash scripts/fetch-plaid-docs.sh` → `docs/api/plaid-mirror/` ([why](README.md))
> **Last verified against plaid.com:** 2026-10-01 (intent create/get, link token, transfer get/cancel,
> event sync, refunds create/get/cancel, Sandbox simulations, webhook verification, error codes,
> reprocessing of returned transfers, Transfer UI Link customization, refund object fields).

---

## 1. Plaid client configuration

| Setting | Value |
|---|---|
| API version | `2020-09-14` (pinned via `Plaid-Version` header) |
| Authentication | `PLAID-CLIENT-ID` / `PLAID-SECRET` headers (never in the body) |
| Transport | JSON `POST` over HTTPS, `wp_remote_post()`, `redirection => 0`, `sslverify => true` |
| Timeout | 30 seconds |
| User-Agent | `PayBridge-for-Plaid/<version>` |
| Environments | `sandbox` → `https://sandbox.plaid.com`, `production` → `https://production.plaid.com` (constants) |
| Endpoint allowlist | `PlaidClient::ALLOWED_PATHS` — anything else throws `ConfigurationException` |
| Sandbox guard | `/sandbox/*` paths throw in Production at the client level |

Errors: HTTP 200 + object → success; `error_type`/`error_code` → `PlaidApiException`
(`is_ambiguous()`: HTTP ≥ 500 or `API_ERROR`; `is_transient()`: ambiguous or 429 /
`RATE_LIMIT_EXCEEDED`); transport failure → `PlaidNetworkException` (ambiguous); anything
else → `PlaidMalformedResponseException` (ambiguous).

---

## 2. Endpoints used

### 2.1 `/transfer/intent/create` (command)

| Field | Value / source |
|---|---|
| `mode` | `PAYMENT` |
| `amount` | Snapshot amount (two-decimal string from the WooCommerce order) |
| `iso_currency_code` | `USD` |
| `description` | Merchant "Bank statement description" (default `PAYMENT`), normalized to `[A-Z0-9 ]`, 1–10 chars. Plaid: required, 1–15 chars; appears on the statement after the company name; ACH shows 10 characters; describe the purpose, not order numbers. |
| `network` | Setting: `same-day-ach` (default, Plaid default) or `ach` |
| `ach_class` | Always `web` (Transfer UI is Nacha WEB; ADR-0013). Plaid: required for ACH; debits `ccd`/`ppd`/`tel`/`web`. |
| `user.legal_name` | **Required.** Billing first + last name; both must be present (no fallback). |
| `user.email_address` | Billing email when valid |
| `user.phone_number` | Omitted (Plaid validates against numbering plans) |
| `metadata` | `pbfp_order_id`, `pbfp_attempt_id`, `pbfp_environment`, `pbfp_site` (ASCII, keys ≤ 40, values ≤ 500, ≤ 50 pairs) |
| `funding_account_id` | Only when configured (Plaid Ledger accounts reject it, ADR-0007). **Documentation inconsistency (2026-09-30):** the request-field list of `/transfer/intent/create` does not list it, while Plaid's own request code samples on the same page pass it. PayBridge keeps the conservative behavior: omitted unless the merchant explicitly configures one. |

No idempotency key exists for this endpoint: ambiguity is handled by never tokenizing an unknown
intent (ADR-0007). Response `transfer_intent` → `TransferIntent` DTO (status must be `PENDING`
and match the snapshot, otherwise manual review).

### 2.2 `/transfer/intent/get` (query)

Request: `transfer_intent_id`. Consumed: `id`, `status` (`PENDING`, `SUCCEEDED`, `FAILED`;
anything else is malformed), `transfer_id` (required when `SUCCEEDED`), `amount`,
`iso_currency_code`, `mode`, `metadata`, `authorization_decision` (`APPROVED`, `DECLINED`),
`authorization_decision_rationale.code` (e.g. `NSF` — the customer may retry up to three
times while the intent stays `PENDING`), `failure_reason.error_code`.
This is the only authority for "Link finished → transfer created".

### 2.3 `/link/token/create` (command, no money movement)

| Field | Value |
|---|---|
| `client_name` | Store name (≤ 30 chars) |
| `language` | `en`, `es` or `fr` from the site locale (a Link customization's language must match) |
| `country_codes` | `["US"]` |
| `products` | `["transfer"]` |
| `user.client_user_id` | HMAC of order + customer (no PII) |
| `transfer.intent_id` | The stored Transfer Intent |
| `link_customization_name` | Merchant setting — **required in Sandbox and Production and always sent** (Account Select “Enabled for one account”, Transfer UI step 1, <https://plaid.com/docs/transfer/using-transfer-ui/>); Plaid's default customization is never relied upon (ADR-0020) |

Response: `link_token`, `expiration` (the expiry is persisted before the token is returned).
Any definitive error is recorded for diagnostics with its code (`INVALID_LINK_CUSTOMIZATION`
per the error reference; on 2026-09-30 Sandbox answered `INVALID_FIELD` for an unknown
customization name and accepted the team's customization named `default`).

### 2.4 `/transfer/get` (query)

Request: `transfer_id`. Consumed: `id`, `status` (`pending`, `posted`, `settled`,
`funds_available`, `cancelled`, `failed`, `returned`), `type` (must be `debit`), `amount`,
`iso_currency_code`, `created`, `failure_reason.failure_code` (return code; `ach_return_code`
deprecated fallback), `failure_reason.description`, `metadata`, `cancellable`,
`standard_return_window` (3 business days after settlement: R01, R02, R03, R29),
`unauthorized_return_window` (61 business days after settlement: R05, R07, R10, R11, R51, R33,
R37, R38, R52, R53), `expected_funds_available_date`, `refunds[]` (`id`, `transfer_id`,
`amount`, `status`, `failure_reason`, `created`). Invalid dates are dropped.

### 2.5 `/transfer/cancel` (command, merchant action only)

Request: `transfer_id`. Allowed only while `/transfer/get` reports `cancellable: true`
(before submission to the network); otherwise `TRANSFER_ERROR` / `TRANSFER_NOT_CANCELLABLE`.
PayBridge never cancels automatically because WooCommerce cancelled an order; the resulting
status is always read back from Plaid.

### 2.6 `/transfer/event/sync` (query)

Request: `after_id` (integer ≥ 0, stored cursor), `count` (1–500, PayBridge uses 100).
Response: `transfer_events[]`, `has_more`. Consumed per event: `event_id` (unsigned 64-bit,
kept as a digit string), `event_type`, `timestamp`, `transfer_id`, `transfer_type`,
`transfer_amount`, `refund_id`, `event_amount`, `intent_id` (populated only for RfP),
`failure_reason.failure_code` / `ach_return_code` / `description`.
Event types: payment lifecycle (`pending`, `posted`, `settled`, `funds_available`, `failed`,
`cancelled`, `returned`); refund lifecycle `refund.pending|posted|settled|failed|cancelled|returned`
(original `transfer_id`, non-null `refund_id`, `transfer_amount` = refund amount); recorded but
ignored: `refund.swept`, `refund.return_swept`, `swept`, `swept_settled`, `return_swept`,
`sweep.*`, `guaranteed`, `adjustment`, `guarantee_reimbursed`, `client_return_recovered`,
`plaid_return_recovered`.
Cursor semantics: every event of a page is durably stored before the cursor advances.
Account scope (ADR-0018): the endpoint returns the events of the **authenticated client**; event IDs
are positions in that client's stream, and another Plaid account in the same environment starts
again at 1. PayBridge therefore keys stored events, the cursor, the payment epoch and the sync lock
by environment **and** account fingerprint. Plaid does not document transfer, refund or event IDs as
unique across clients, so no lookup crosses accounts.

### 2.7 `/transfer/refund/create` (command)

| Field | Value |
|---|---|
| `transfer_id` | The order's current debit transfer |
| `amount` | WooCommerce refund amount (two-decimal string), ≤ remaining refundable amount |
| `idempotency_key` | **Required**, ≤ 50: `pbfp-` + 44 hex of sha256(site, environment, order, WooCommerce refund ID, attempt, amount). Plaid: retrying with the same key creates a single refund. |

Plaid rules: debit transfers only; at most 10 refunds per transfer; total ≤ transfer amount;
within 180 days of the transfer's creation; not for `cancelled`, `failed` or `returned`
transfers; funded from the Ledger's available balance (rejected otherwise); no hold time
required (PayBridge still requires settlement, ADR-0016). Response `refund` (`id`,
`transfer_id`, `amount`, `status`, `failure_reason`, `created`, `network_trace_id`) is checked
against the request.

### 2.8 `/transfer/refund/get` (query) and `/transfer/refund/cancel` (command)

`get`: `refund_id` → `refund` object (`id`, `transfer_id`, `amount`, `status` — `pending`,
`posted`, `settled`, `cancelled`, `failed`, `returned` —, `failure_reason`, `ledger_id`, `created`,
`network_trace_id`). **No return window is documented for refunds** (no field, none in the refund
guide); PayBridge watches a settled refund as long as the refunded debit (its unauthorized return
window + buffer) and at least 14 days (ADR-0021). `cancel`: `refund_id`, allowed only before submission to the network;
used automatically only for pending refunds of a debit that was returned (ADR-0016).

### 2.9 `/transfer/configuration/get` and `/transfer/ledger/get` (queries)

Used by "Test connection" only. Errors are classified (`ConnectionTester::classify()`):
`INVALID_API_KEYS`/`UNAUTHORIZED_ENVIRONMENT` → invalid credentials; `INVALID_PRODUCT`/
`PRODUCT_NOT_ENABLED`/`PRODUCTS_NOT_SUPPORTED` → Transfer not enabled; `UNAUTHORIZED_ACCESS`/
`UNAUTHORIZED_ROUTE_ACCESS`/`ADDITIONAL_CONSENT_REQUIRED` → permission denied; 429 → rate
limited; 5xx/`API_ERROR`/`PLANNED_MAINTENANCE` → Plaid unavailable; transport → network error.
Ledger presence drives funding-account advice.

### 2.10 `/webhook_verification_key/get` (query)

Request: `key_id` (JWT `kid`). Response `key` (`kty=EC`, `crv=P-256`, `alg=ES256`, `x`, `y`,
`created_at`, `expired_at`). Cached 24 h; unknown IDs negatively cached 5 min; ≤ 10 lookups
per minute and environment; failures are never permission to skip verification.

### 2.11a Reprocessing returned transfers (verified 2026-10-01)

<https://plaid.com/docs/api/products/transfer/initiating-transfers/#transfercreate> (`description`):
"If reprocessing a returned transfer, the `description` field must be `"Retry 1"` or `"Retry 2"`.
You may retry a transfer up to 2 times, within 180 days of creating the original transfer. Only
transfers that were returned with code `R01` or `R09` may be retried."
<https://plaid.com/docs/transfer/troubleshooting/>: "A returned transfer can be retried up to 2
times. When you try to reprocess a returned transfer, the `description` field in your
`/transfer/create` request must be `"Retry 1"` or `"Retry 2"` […] retries are only allowed for
transfers returned as R01 or R09"; "you cannot resubmit a payment after encountering an R10
error"; unauthorized returns (R05, R07, R10, R11, …) have a 60-day window.
<https://plaid.com/docs/transfer/creating-transfers/> (description guidance): "Returned-transfer
retry — `Retry 1` / `Retry 2` — These values are required for R01/R09 retries".

These rules exist only for `/transfer/create`. `/transfer/intent/create` (Transfer UI) documents no
retry marking — its `description` is "A description for the underlying transfer", 1–15 characters —
and the Transfer UI guide mentions retries only for an NSF-declined authorization of a still
`PENDING` intent. PayBridge therefore never originates a second debit for an order after one of
its transfers was returned (`ReturnRetryPolicy`, ADR-0019).

### 2.11 Sandbox-only (never in Production)

- `/sandbox/transfer/fire_webhook` (`webhook` URL) — `wp paybridge-plaid fire-sandbox-webhook`.
- `/sandbox/transfer/refund/simulate` (`refund_id`, `event_type` ∈ `refund.posted`,
  `refund.settled`, `refund.failed`, `refund.returned`; `refund.posted` only after the refunded
  transfer settled) — `wp paybridge-plaid simulate-refund`, Sandbox gate.
- `/sandbox/transfer/simulate` — allowlisted for test tooling.

---

## 3. Webhook `TRANSFER_EVENTS_UPDATE`

| Property | Value |
|---|---|
| `webhook_type` / `webhook_code` | `TRANSFER` / `TRANSFER_EVENTS_UPDATE` |
| Endpoint | `POST /wp-json/paybridge-for-plaid/v1/webhook` |
| Verification | `Plaid-Verification` ES256 JWT: alg allowlist, `kid`, JWK, signature, `iat` ≤ 5 min (+60 s), constant-time `request_body_sha256` |
| Effect | Queue one `/transfer/event/sync` (duplicates coalesce); the body never mutates state |
| Other environment / unrelated webhooks | 200, nothing queued |

---

## 4. State mapping

| Plaid transfer status / event | PayBridge state | WooCommerce |
|---|---|---|
| intent created / token issued | `intent_created` / `intent_pending` | pending |
| intent `SUCCEEDED` | `transfer_created` | on-hold |
| `pending`, `posted` | `pending`, `posted` | on-hold |
| `settled` | `settled` | on-hold or paid (setting) |
| `funds_available` | `funds_available` | paid (`payment_complete()`) |
| `failed` | `failed` | failed |
| `cancelled` | `cancelled` | cancelled |
| `returned` | `returned` | failed + explicit return marker (ADR-0012) |
| refund `pending`…`returned` | refund state (never payment state) | WooCommerce refund record |

---

## 5. Sandbox automatic simulations (verified 2026-10-01)

| Transfer amount | Path |
|---|---|
| `$11.11` | pending → posted → settled → funds available |
| `$22.22` | pending → failed |
| `$33.33` | pending → posted → settled → funds available → returned (R01) |
| `$44.44` | … funds available → returned (R02) |
| `$55.55` | … funds available → returned (R16) |
| `$66.66` | pending → posted → settled → returned |

| Refund amount | Path |
|---|---|
| `$1.11` | pending → posted → settled → returned |
| `$2.22` | pending → failed |

Other refunds stay `pending` until `/sandbox/transfer/refund/simulate`. Sandbox transfers of
other amounts stay `pending` until simulated. Simulated changes do not fire webhooks; use
`/sandbox/transfer/fire_webhook`. Sandbox Plaid Ledgers start with $100 available.

---

## 6. Known constraints and decisions

1. `funding_account_id` is optional (Plaid Ledger rejects it, ADR-0007).
2. `user.phone_number` is omitted.
3. `event_id` is an unsigned 64-bit integer, stored and compared as a decimal string.
4. API version pinned to `2020-09-14`; updating requires re-verifying every consumed shape.
5. A refunded debit can still be returned (R01 within 2 banking days of settlement, unauthorized
   returns for 60 calendar days); Plaid does not reimburse both — PayBridge warns and alerts.
6. The refund-then-return scenario cannot be produced in Sandbox (returns are simulated only
   from `posted`, and the automatic amounts return immediately); it is covered by the
   deterministic Plaid double.
7. A returned transfer is never followed by another debit for the same order (§2.11a, ADR-0019).
8. Everything read from the event stream is scoped by environment + account (§2.6, ADR-0018).

---

## 7. Freshness protocol

Before modifying any Plaid integration code: read this document, verify the endpoint on
plaid.com, update this document and the fixtures/tests when the contract changed, and add or
supersede an ADR when the architecture must change.
