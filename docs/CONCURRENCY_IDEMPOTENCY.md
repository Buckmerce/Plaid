# Concurrency and Idempotency

## 1. Threat

A normal WooCommerce checkout can invoke payment logic multiple times: double-clicks, browser
retries, network failures, parallel tabs, PHP worker concurrency, Action Scheduler retries and
webhook replays. With bank transfers and refunds, duplicate side effects are unacceptable.

## 2. Mechanisms

| Mechanism | Where | Protects |
|---|---|---|
| Connection-bound advisory lock (`GET_LOCK`, non-blocking) | `DatabaseMutex` | one payment operation per order (`payment:{id}`), one refund creation per order (`refund:{id}`), one event-sync worker per Plaid event stream (`event-sync:{environment}:{account}`, ADR-0018), one reconciliation run |
| Durable reservation with owner token + 120 s lease, compare-and-set transitions | `PaymentLockStore` (ADR-0003) | Transfer Intent creation; expired `creating` leases become `uncertain`, never silently reused |
| Payment index compare-and-set bind | `PaymentLockStore::bind_transfer()` | a transfer can be bound once, to the stored intent only |
| Unique event identity `(environment, account_fp, event_id)` + leased claims within one account scope | `TransferEventStore` | each Plaid event processed once; another account's event with the same ID is a different event; crashed workers' events reclaimed; a corrupted stored row is abandoned instead of blocking the stream |
| Refund reservation: unique idempotency key, unique `wc_refund_id`, unique `(environment, account_fp, refund_id)`, owner token + lease | `RefundStore` (ADR-0016, ADR-0018) | one WooCommerce refund → at most one Plaid refund; refund IDs never matched across accounts |
| Return retry policy at the single new-debit entry point | `ReturnRetryPolicy` via `PaymentAttemptService` (ADR-0019) | a returned transfer is never followed by another debit for the order |
| Plaid idempotency key (`pbfp-` + sha256 of site, environment, order, WooCommerce refund, attempt, amount) | `RefundService::idempotency_key()` | retried refund creates return the same Plaid refund |
| Refund status compare-and-set on the previous status | `RefundStore::transition()` | concurrent events/reconciliation apply each refund transition once |
| Verified order persistence (read-back) | `OrderPersistence` (ADR-0010) | no WooCommerce side effect on top of an unsaved payment state |
| Idempotent projection | `OrderPaymentProjector` | replays re-apply safely; notes/alerts/emails only on APPLY |

## 3. Payment creation

```text
lock payment:{order} → reload order → decide reuse | replace | create
→ acquire reservation (INSERT IGNORE / claim uncertain|failed|retired|expired preparing)
→ persist snapshot (verified) → payment epoch → begin_creation (CAS preparing→creating)
→ /transfer/intent/create
→ mark_created (CAS, records intent ID) → order meta (verified) → intent_created
```

Timeouts/5xx during create → `uncertain`: the unknown intent can never receive a Link token
(tokens are issued only for the stored intent), so a new attempt is safe (ADR-0007). A DB
failure before the remote call fails closed; after it, the intent ID is recovered from the
reservation row.

## 4. Refund creation

```text
lock refund:{order} → re-read eligibility and remaining amount → double-submit guard (same amount < 60 s)
→ reserve row (creating, unique key) → /transfer/refund/create (same key)
→ success: complete_creation (CAS on owner token) → notes/meta
→ definitive error: rejected
→ ambiguous: retry once with the same key → Plaid refund list lookup → uncertain (blocks further refunds)
```

An uncertain refund is resolved only by reading Plaid (event with the refund ID, or
`transfer.refunds[]` via reconciliation): adopted (WooCommerce refund record restored) or
void after 30 minutes. The create request is never re-sent autonomously.

## 5. Contention

A live lock or lease never causes a second provider call. Checkout gets "already being
prepared", `/complete` answers `unverified` (the browser retries), a second refund gets
"another refund is being processed", a second event worker returns `busy`.

## 6. Expired leases

Expiry is not evidence that the previous worker failed before the remote side effect: an
expired `creating` payment reservation or refund reservation becomes `uncertain` and is
resolved from Plaid state.

## 7. Idempotent handlers

Browser completion, webhook dispatch (unique Action Scheduler job), event processing,
reconciliation, order projection, refund events, manual "Sync with Plaid", manual cancel
(an already-cancelled transfer returns the existing outcome without a Plaid call) and
Action Scheduler retries are all safe to repeat.

## 8. Test matrix (implemented)

| Scenario | Test |
|---|---|
| Place Order double click / repeated `process_payment` | `wp-cli-payment-flow.php`, `browser-smoke.js` |
| Parallel `process_payment` in two PHP processes | `concurrency.php` |
| Payment page refresh / Link opened twice / Link button double click | `wp-cli-payment-flow.php`, `browser-smoke.js`, `tests/js` |
| Completion callback repeated / two concurrent completions | `wp-cli-payment-flow.php`, `concurrency.php` |
| Plaid intent timeout, 5xx, response lost after creation | `wp-cli-payment-flow.php` |
| Remote intent success + local crash (save failure) | `wp-cli-payment-flow.php` |
| Expired reservation lease / reservation DB failure / live lease | `wp-cli-payment-flow.php` |
| Duplicate webhook / duplicate event / parallel event workers | `wp-cli-webhook-rest.php`, `wp-cli-payment-flow.php`, `concurrency.php` |
| Account switch: identical event and refund IDs on two accounts, webhook of account B | `wp-cli-accounts.php` |
| Schema 2 → 3 upgrade, re-reading a stream from 0 | `wp-cli-migration.php` |
| Action Scheduler retry | `wp-cli-payment-flow.php`, `wp-cli-lifecycle.php` |
| Refund double click / identical refund / retried `process_refund` | `wp-cli-refunds.php` |
| Parallel refunds from two processes | `concurrency.php` |
| Refund timeout, 502 after creation, unknown outcome, response lost (real Plaid) | `wp-cli-refunds.php`, `sandbox-refunds.php` |
| Remote refund success + local persistence failure | `wp-cli-refunds.php` |
