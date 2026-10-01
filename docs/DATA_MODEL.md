# Data Model

## 1. Design principle

WooCommerce order data stays in WooCommerce CRUD/HPOS. PayBridge custom tables are used only for concurrency/event-processing semantics.

## 2. Order metadata

Private keys should include:

```text
_pbfp_environment
_pbfp_payment_state
_pbfp_payment_snapshot
_pbfp_transfer_intent_id
_pbfp_transfer_intent_status
_pbfp_transfer_id
_pbfp_transfer_status
_pbfp_request_id
_pbfp_last_sync_at
_pbfp_failure_code
_pbfp_last_event_id
```

Do not store Plaid Secret, auth headers or bank credentials in order metadata.

## 3. Payment snapshot

Recommended immutable JSON/value-object fields:

```text
schema_version
attempt_id
order_id
amount
currency
environment
gateway_id
created_at
```

If serialized, validate schema before use. Do not mutate the original snapshot to reflect later provider states.

## 4. Event table

Suggested table:

```text
{prefix}paybridge_plaid_events
```

Suggested conceptual columns:

- `id` bigint primary key;
- `environment` varchar;
- `provider_event_id` bigint/string according to current API;
- `event_type` varchar;
- `transfer_id` varchar nullable;
- `order_id` bigint nullable;
- `status` varchar;
- `owner_token` varchar nullable;
- `lease_expires_at` datetime nullable;
- `attempts` int;
- `error_code` varchar nullable;
- `error_message` text nullable, sanitized;
- `provider_created_at` datetime nullable;
- `created_at` datetime;
- `updated_at` datetime.

Unique identity must include environment if provider IDs are environment-scoped.

## 5. Payment lock table

Suggested:

```text
{prefix}paybridge_plaid_payment_locks
```

Conceptual columns:

- resource key/order ID;
- owner token;
- lease expiry;
- created/updated timestamp.

Use a unique index that makes concurrent acquisition atomic.

## 6. Options

Primary WooCommerce settings:

```text
woocommerce_paybridge_plaid_settings
```

Additional plugin options must be prefixed with `paybridge_plaid_` or equivalent documented naming.

## 7. Schema management

- schema version starts independently for PayBridge;
- migrations are forward-only and tested;
- after migration, verify runtime-required indexes/columns;
- do not mark migration complete if verification fails;
- do not migrate or delete another plugin's tables.

## 8. Retention/uninstall

Financial/audit state is retained by default.

Explicit cleanup may delete only PayBridge-owned data. Document privacy implications and merchant controls.

## 9. Implemented schema (schema version `3`, plugin 1.0)

Migrations are forward-only and idempotent (`Installer::install()`): explicit pre-steps →
`dbDelta()` → explicit post-steps → `schema_is_valid()` verifies every column, NOT NULL account
columns, every unique key and the absence of superseded keys → the version is stored.

- Schema 1 → 2 adds the payment-index monitoring columns and the refunds table and attributes
  existing rows to the configured Plaid account (tested in `wp-cli-smoke.php`).
- Schema 2 → 3 (ADR-0018) scopes Plaid identities by account: `events.account_fp` (NOT NULL; existing
  rows → `legacy`), `UNIQUE account_event (environment, account_fp, event_id)` replacing
  `environment_event`; `refunds.account_fp` NOT NULL (NULL → `legacy`), `UNIQUE account_refund
  (environment, account_fp, refund_id)` replacing `environment_refund`; per-account cursor, epoch and
  sync-health options (the schema-2 epoch is copied to every account with payments in that
  environment; the schema-2 cursor is not attributed). Tested in `wp-cli-migration.php` with
  payments, refunds, returns, events, cursor and epoch; running it twice is harmless.

The gateway stays unavailable while the schema is invalid.

### Order metadata (`src/Payment/OrderMeta.php`)

All keys are private (`_pbfp_`) and written only through WooCommerce CRUD; critical keys are
read back after `save()` (`OrderPersistence`, ADR-0010). Per-attempt keys are archived and
cleared when a new attempt starts (`AttemptHistory::ATTEMPT_KEYS`, ADR-0017).

| Key | Content |
|---|---|
| `_pbfp_environment` | `sandbox` / `production` of the attempt |
| `_pbfp_account_fingerprint` | Plaid account identity of the attempt (ADR-0015) |
| `_pbfp_payment_state` | State machine state (`docs/STATE_MACHINE.md`) |
| `_pbfp_payment_snapshot` | Immutable JSON: `schema_version`, `attempt_id` (32 hex), `order_id`, `amount` (decimal string), `currency`, `environment`, `gateway_id`, `created_at` |
| `_pbfp_transfer_intent_id`, `_pbfp_transfer_intent_status` | Current Transfer Intent |
| `_pbfp_transfer_id`, `_pbfp_transfer_status` | Transfer created by the intent and its last Plaid status |
| `_pbfp_transfer_created_at`, `_pbfp_settled_at`, `_pbfp_funds_available_at`, `_pbfp_returned_at` | Provider timestamps (first occurrence) |
| `_pbfp_standard_return_window`, `_pbfp_unauthorized_return_window`, `_pbfp_expected_funds_available_date` | Dates from `/transfer/get` (YYYY-MM-DD); only ever become known, never unknown |
| `_pbfp_transfer_cancellable` | `yes`/`no` from the last `/transfer/get` |
| `_pbfp_request_id` | Last Plaid `request_id` |
| `_pbfp_last_sync_at`, `_pbfp_last_event_id` | Last provider read / processed event |
| `_pbfp_failure_code`, `_pbfp_return_code`, `_pbfp_failure_description` | Sanitized codes and a ≤ 200-character markup-free provider reason |
| `_pbfp_manual_review_reason`, `_pbfp_manual_review_resolution` | Review reason; merchant decision (JSON: decision, user, time, released) |
| `_pbfp_link_token_expires_at` | Latest expiry of a Link token for the current intent |
| `_pbfp_return_alerted` | Return alert/email sent for the current attempt |
| `_pbfp_retired_attempts` | Attempt history (ADR-0017): attempt/intent/transfer IDs, environment, account, amount, currency, created, final state, transfer status, failure/return code and reason, transfer-created/settled/funds-available/returned/paid times, unauthorized window, reason, retired time. Attempts with a transfer are never dropped; others capped at 20. |

WooCommerce refund objects (`WC_Order_Refund`) carry `_pbfp_refund_row`, `_pbfp_refund_id` and
`_pbfp_refund_status`.

### `{prefix}paybridge_plaid_events`

`id` PK, `environment`, `account_fp` (16-hex account fingerprint, or `legacy` for schema-2 rows),
`event_id` (unsigned bigint), `event_type`, `transfer_id`,
`order_id`, `event_data` (minimal JSON: IDs incl. `refund_id`, type, amounts, failure/return
codes, timestamp), `status` (`received`, `processing`, `processed`, `ignored`, `unmatched`,
`retry`, `abandoned`), `owner_token`, `lease_expires_at`, `attempts` (max 20), `error_code`,
`provider_created_at`, `created_at`, `updated_at`, `processed_at`.
Unique `account_event (environment, account_fp, event_id)`; keys on
`scope_status (environment, account_fp, status, lease_expires_at)` (claims, backlog),
`(status, lease_expires_at)`, `transfer_id`, `(order_id, status)`, `created_at`. Payment and refund events are processed;
sweeps, adjustments and guarantee events are recorded as `ignored`.

### `{prefix}paybridge_plaid_payment_locks` (reservation + payment index)

One row per PayBridge order for its current attempt (ADR-0003, ADR-0008).
`order_id` PK, `environment`, `account_fp`, `status` (`preparing`, `creating`, `created`,
`uncertain`, `failed`, `retired`), `owner_token`, `lease_expires_at` (120 s), `attempts`,
`attempt_id`, `snapshot_hash`, `transfer_intent_id`, `transfer_id`, `payment_state`,
`reconcile_after` (next Plaid read), `monitor_until` (end of monitoring), `error_code`,
`created_at`, `updated_at`. Keys on `(status, lease_expires_at)`, `transfer_intent_id`,
`transfer_id`, `(environment, status, reconcile_after)`, `(environment, payment_state)`,
`account_state (environment, account_fp, status)`. Lookups by transfer or intent ID are limited
to the configured account (rows without a fingerprint predate schema 2 and still match).

### `{prefix}paybridge_plaid_refunds`

One row per Plaid refund created or discovered (ADR-0016).
`id` PK, `order_id`, `wc_refund_id` (UNIQUE, NULL for external refunds), `environment`,
`account_fp` (NOT NULL; `legacy` when unknown), `attempt_id`, `transfer_id` (original debit), `refund_id` (Plaid),
`idempotency_key` (UNIQUE, ≤ 50), `amount` (two-decimal string), `currency`, `status`
(`docs/STATE_MACHINE.md` §5), `origin` (`woocommerce`, `external`), `failure_code`,
`request_id`, `owner_token`, `lease_expires_at`, `reconcile_after`, `monitor_until`,
`last_event_id`, `checks`, `created_at`, `updated_at`.
Unique `account_refund (environment, account_fp, refund_id)`; keys on `(order_id, status)`,
`transfer_id`, `(environment, reconcile_after)`, `account_reconcile (environment, account_fp,
reconcile_after)`, `status`. Lookups by refund ID are scoped by account; `legacy` rows still match
so an upgraded refund is never recorded twice.

### Options

`woocommerce_paybridge_plaid_settings`, `paybridge_plaid_schema_version`,
`paybridge_plaid_last_reconciliation`, `paybridge_plaid_last_reconciliation_error`,
`paybridge_plaid_last_connection_test`, `paybridge_plaid_last_link_token_error` (last definitive
Link token error code), `paybridge_plaid_payment_alerts`, `paybridge_plaid_last_webhook`,
`paybridge_plaid_last_webhook_rejection`.

Per Plaid account (`{environment}_{account}`, account = 16-hex fingerprint, ADR-0018):
`paybridge_plaid_event_cursor_{environment}_{account}` (sync cursor),
`paybridge_plaid_first_intent_at_{environment}_{account}` (payment epoch, ADR-0011),
`paybridge_plaid_event_sync_{environment}_{account}` (last sync, last error with category,
consecutive failures for backoff).

Schema-2 options kept after an upgrade for auditing only (never read):
`paybridge_plaid_event_cursor_{environment}`, `paybridge_plaid_first_intent_at_{environment}`,
`paybridge_plaid_last_event_sync`, `paybridge_plaid_last_event_sync_error`,
`paybridge_plaid_event_sync_failures`.

Short-lived transients `pbfp_*` (webhook verification keys, connection-test results, order-panel
notices, account-guard notices, REST rate limits).

### Uninstall

Always: unschedule PayBridge actions. Only with **Uninstall cleanup** enabled: delete the
options above (the per-account ones matched exactly by their pattern, never by prefix guessing),
`pbfp_` transients and the three tables. Order metadata and WooCommerce refund
records are never deleted.
