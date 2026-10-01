# Webhooks and Transfer Events

## 1. Endpoint

```text
POST /wp-json/paybridge-for-plaid/v1/webhook
```

The route is publicly reachable at the HTTP layer but not trusted until Plaid verification succeeds.

## 2. Verification pipeline

Before parsing business semantics:

1. read the exact raw request body;
2. obtain `Plaid-Verification` header case-insensitively;
3. decode JWT header without trusting it;
4. require `alg=ES256`;
5. read `kid`;
6. fetch/cache key via `/webhook_verification_key/get`;
7. verify JWK/JWT signature with maintained library;
8. validate `iat` freshness according to current Plaid guidance;
9. compute SHA-256 of the exact raw body;
10. compare to `request_body_sha256` using constant-time comparison;
11. only then parse/dispatch webhook.

No order mutation may occur before step 10 passes.

## 3. Key cache

Cache Plaid verification keys by `kid` with bounded lifetime. Respect provider key expiration metadata.

A key-fetch failure is not permission to skip signature verification.

## 4. Transfer webhook semantics

`TRANSFER_EVENTS_UPDATE` means new Transfer events are available.

The webhook is intentionally treated as a notification, not as detailed transfer truth.

After verification, enqueue a unique/bounded Action Scheduler sync job.

## 5. Event sync

`/transfer/event/sync` is called with the persisted cursor/event position required by current Plaid API.

Process returned events in deterministic order.

For each event:

1. derive durable event identity;
2. reserve event for processing;
3. resolve corresponding local payment/order;
4. validate environment and identifiers;
5. apply state machine transition;
6. project to WooCommerce;
7. mark event processed;
8. advance durable cursor only safely.

## 6. Event store properties

The event table must support:

- unique provider event identity;
- processing status;
- owner token;
- lease expiration;
- attempts;
- error code/message (sanitized);
- order ID;
- transfer ID;
- environment;
- timestamps.

## 7. Replay behavior

Duplicate webhook: harmless.  
Duplicate event: harmless.  
Worker dies after reserving event: lease expires and another worker resumes.  
Event handler fails: bounded retry / safe failure record.

## 8. Cursor safety

Do not advance a global cursor past events that were never durably recorded. Prefer:

```text
fetch batch
→ persist/reserve batch identities
→ advance sync position
→ process durable rows
```

or another design that cannot lose events on process crash.

Document chosen implementation in an ADR if it differs.

## 9. Implementation (1.0)

| Request | Response | Effect |
|---|---|---|
| Verified `TRANSFER` / `TRANSFER_EVENTS_UPDATE` for the configured environment | 200 | One unique `paybridge_plaid_transfer_event_sync` action (duplicates coalesce) |
| Verified webhook for the other environment | 200 | Nothing queued (logged `webhook_environment_mismatch`) |
| Verified unrelated webhook type/code | 200 | Nothing queued |
| Missing/malformed header, wrong `alg`, unknown/expired key, bad signature, stale `iat`, body hash mismatch, non-JSON body | 401 | Nothing queued |
| Key lookup failed, was rate limited by Plaid (HTTP 429) or by PayBridge (10 lookups/min) | 503 | Nothing queued; Plaid retries the notification |
| Body larger than 64 KiB | 413 | Nothing queued |
| Enqueue failure / plugin not configured | 503 | Nothing queued |

- Verified webhooks are recorded in `paybridge_plaid_last_webhook` and rejections in
  `paybridge_plaid_last_webhook_rejection` (throttled to one write per minute); both appear in
  the diagnostics report and `wp paybridge-plaid status`.
- Unknown key IDs are negatively cached for five minutes; rate limiting and ambiguous errors
  never are.
- Scope (ADR-0018): the stream belongs to the authenticated Plaid client. Events are stored under
  `(environment, account_fp, event_id)`, the cursor is `paybridge_plaid_event_cursor_{environment}_{account}`,
  the lock is `event-sync:{environment}:{account}`, and claims, backlog checks and correlations never
  leave the configured account. After an account switch the new stream starts at 0 and event 1 of
  the new account is a new event; the previous account's rows and cursor stay for auditing.
- Cursor: each page from `/transfer/event/sync` is stored with `INSERT IGNORE` before the
  cursor option advances; processing then claims stored rows under a lease.
- Event rows for transfers that are not in the payment index are ignored as
  `before_first_payment` when they predate the store's first Transfer Intent with this account (ADR-0011, ADR-0018),
  otherwise resolved through `/transfer/get` metadata (ADR-0010); a 429 or ambiguous error
  leaves the row `unmatched` for a backoff retry.
- End-to-end evidence with genuine Plaid-signed webhooks through a public HTTPS URL:
  `npm run test:sandbox:ngrok` (see `docs/TESTING_QA.md` §11).
- Webhooks and event sync run whether or not the gateway accepts new payments (ADR-0014); only
  missing credentials stop them. The recurring reconciliation runs while there is work
  (ADR-0021); a verified webhook queues an event sync even when reconciliation is stopped.

## 10. Event routing

Ignore codes added in 1.0: `account_mismatch` (the order's attempt belongs to another Plaid account;
never changed by this stream).

| Stored event | Handler | Effect |
|---|---|---|
| `pending`, `posted`, `settled`, `funds_available`, `failed`, `cancelled`, `returned` with no `refund_id` | `TransferEventProcessor` | Payment state machine for the order of the transfer (index match, or adoption through intent metadata, ADR-0010) |
| any event with a non-null `refund_id` whose type is `refund.pending|posted|settled|failed|cancelled|returned` | `RefundEventHandler` (ADR-0016) | Refund state machine by `refund_id`; adopts an unlinked PayBridge reservation of the same transfer and amount (retried while its create is still in flight); records refunds created outside PayBridge for this store's transfers (`origin=external`, alert); ignores foreign refunds (`foreign_refund`, `before_first_payment`) |
| `refund.swept`, `refund.return_swept`, sweeps, `adjustment`, `guaranteed`, `*_recovered`, … | — | Recorded as `ignored` (no state change) |

A refund event is never interpreted as a payment event, even if its type were unprefixed:
the presence of `refund_id` decides.

## 11. Background jobs (Action Scheduler, group `paybridge-for-plaid`)

| Hook | Trigger | Bounded by | Errors |
|---|---|---|---|
| `paybridge_plaid_transfer_event_sync` | verified webhook (unique), follow-up when more events or processable rows remain | 10 pages / 200 events / 40 s per run | transient: follow-up with exponential backoff 1→15 min (per-account failure count in `paybridge_plaid_event_sync_{environment}_{account}`); permanent (credentials): diagnostics + next reconciliation |
| `paybridge_plaid_reconcile` | recurring every 15 min while maintenance is active: gateway enabled, or the configured account has monitored payments, open refunds, waiting or deferred events or a failed sync (ADR-0021) | 25 payments, 25 refunds, 25 backfill rows, 45 s | per-order retry after 15 min; run error recorded with category |
| `paybridge_plaid_reconcile_continue` | when a run hit its bounds | same | same |

Event rows retry with exponential backoff (1 min → 6 h) and are `abandoned` after 20 attempts,
which diagnostics report. Overdue PayBridge actions (> 30 min) and `DISABLE_WP_CRON` are shown
in diagnostics, the settings status and Site Health.
