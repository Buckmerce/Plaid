# ADR-0018: Plaid event streams, cursors, epochs and refund identities are scoped by account

**Status:** Accepted (1.0.0). Supersedes the environment-only scoping of ADR-0004 (event
identity), ADR-0011 (payment epoch) and ADR-0016 (unique `(environment, refund_id)`); extends
ADR-0015 (account identity).

## Context

`/transfer/event/sync` returns the transfer events **of the authenticated Plaid client**. Event
IDs are positions in that client's stream; a different Plaid account in the same environment has
its own stream starting again at event 1. Schema 2 scoped the event store, the sync cursor, the
payment epoch, the event-sync lock and refund identities by `environment` only. After a legitimate
account switch (ADR-0015 allows it once nothing is monitored):

- account B's events 1..N were `INSERT IGNORE`d away as "duplicates" of account A's events 1..N;
- account B's stream was read from account A's cursor (e.g. `after_id = 1000`), silently skipping
  B's first 1000 events;
- account A's epoch hid or exposed account B's history incorrectly;
- a refund ID of account B could be matched to account A's refund row.

Plaid does not document transfer, refund or event identifiers as unique across clients.

## Decision

- **Scope = environment + account fingerprint** (`Settings\AccountScope`, the non-secret
  `Settings::account_fingerprint()` of ADR-0015). Rotating the secret keeps the Client ID and
  therefore the scope and its cursor.
- **Schema 3:** `paybridge_plaid_events.account_fp` (NOT NULL) with
  `UNIQUE account_event (environment, account_fp, event_id)` and
  `KEY scope_status (environment, account_fp, status, lease_expires_at)`;
  `paybridge_plaid_refunds.account_fp` NOT NULL with
  `UNIQUE account_refund (environment, account_fp, refund_id)`. The environment-only unique keys
  are dropped (their presence fails `Installer::schema_is_valid()`).
- **Cursor** `paybridge_plaid_event_cursor_{environment}_{account_fp}`, **epoch**
  `paybridge_plaid_first_intent_at_{environment}_{account_fp}`, **sync health**
  `paybridge_plaid_event_sync_{environment}_{account_fp}` (last sync, last error, consecutive
  failures), **mutex** `event-sync:{environment}:{account_fp}`. A new account starts at cursor 0
  and without an epoch; another account's values are never read and are kept for auditing.
- **Every read, claim and correlation is scoped:** `TransferEventStore::record/claim_batch/
  has_processable/counts`, payment-index lookups by transfer or intent ID, `RefundStore::
  find_by_refund_id/for_transfer/due/counts`, `TransferEventProcessor` and `RefundEventHandler`
  (an order whose attempt belongs to another account is ignored with `account_mismatch`),
  external-refund recording (the refund's account is the stream's account).
- **Migration 2 → 3 (forward-only, idempotent):** existing event rows keep their history in the
  `legacy` scope — their account cannot be proven because the schema-2 cursor was shared by every
  account of the environment. The schema-2 cursor is not attributed to any account: each account's
  stream is re-read from 0, which is safe because event processing is idempotent (state-machine
  NOOP/STALE, refund lookups also match legacy refund rows so nothing is recorded twice). Refund
  rows keep their recorded account (a NULL account becomes `legacy`). The schema-2 environment
  epoch is copied to every account that has payments in that environment — it is never later than
  that account's own first intent, so it can only make classification more conservative. Legacy
  options stay for auditing and are removed only by the opt-in uninstall cleanup.

## Consequences

- Account A's event 5 and account B's event 5 coexist; a webhook for B only reads, stores,
  advances and processes B's stream (`tests/Integration/wp-cli-accounts.php`).
- After an upgrade the first synchronisation re-reads the configured account's history once;
  events older than the epoch are classified without API calls (ADR-0011).
- Diagnostics show the configured account's stream (cursor, epoch, backlog, sync health) and count
  the retained events of previous accounts and schema 2 separately.
- Tests: `tests/Unit/PaymentEpochTest.php`, `tests/Unit/OperationalHelpersTest.php`,
  `tests/Integration/wp-cli-accounts.php`, `tests/Integration/wp-cli-migration.php`,
  `tests/Integration/wp-cli-smoke.php`.
