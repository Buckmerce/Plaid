# Changelog

## 1.0.0

Production hardening release. Upgrading migrates the database schema to version 3 automatically
(refunds table, payment index columns, account-scoped Plaid identities); existing payments are
backfilled and no history is lost. Requires WooCommerce 8.7 or later.

### Payments and ACH lifecycle
- Payments are monitored until Plaid's unauthorized return window closes (reported by
  `/transfer/get`, conservative fallback of 95 days after settlement), independent of the order
  date and of the gateway's enabled switch. Disabling Pay by Bank now only stops new payments.
- Explicit returned-payment semantics: a returned debit sets the order to Failed, keeps the
  original paid date, transaction ID and history, shows "Bank payment returned" in the orders
  list, raises a persistent admin notice and emails the merchant.
- Safe repayment after a failed payment (no money moved) as a new attempt; every earlier attempt is
  archived in the order's payment history and money-moving attempts are never dropped.
- No bank re-debit after an ACH return (`ReturnRetryPolicy`, ADR-0019): Plaid allows reprocessing
  only R01/R09 returns, at most twice, within 180 days and marked "Retry 1/2" on `/transfer/create`;
  Transfer UI cannot mark retries and R10/R07/R11… may never be resubmitted. Returned orders are not
  offered Pay by Bank again (also after the customer tried another payment method for the order);
  customers and merchants are told why.
- Manual-review workflow with audited decisions; "Let the customer pay again" only when Plaid
  confirms no transfer exists and no Link session can still be used.
- Optional merchant action "Cancel bank payment" while Plaid reports the transfer cancellable.

### Refunds (new)
- Native WooCommerce refunds through `/transfer/refund/create`: full, partial and multiple partial
  refunds, with a dedicated refund state machine, refund table and deterministic idempotency keys.
- Exact remaining-amount rules (failed, cancelled and returned refunds do not count), Plaid limits
  (10 refunds, 180 days), duplicate-submit guard and a per-order refund mutex.
- Ambiguous refund creation (timeouts, 5xx, lost responses) is retried with the same idempotency
  key and resolved from Plaid; it never creates a second refund.
- Refund events from the same verified event pipeline, refund reconciliation via
  `/transfer/refund/get`, alerts for failed, returned, unconfirmed and external refunds.
- Original debit returned after a refund: pending refunds are cancelled where possible and a
  critical alert is raised.

### Plaid configuration
- The ACH class is always WEB (Transfer UI); the free-form ACH class setting was removed.
- A Link customization with Account Select "Enabled for one account" is required in Sandbox and
  Production and always sent to `/link/token/create` (ADR-0020).
- The customer's legal name is required (billing first and last name); no placeholder names.
- New "Bank statement description" setting (up to 10 characters, default `PAYMENT`).
- Plaid Client ID/environment changes are refused while Production payments or refunds are still
  monitored; a non-secret account fingerprint is stored with every payment and refund.
- Plaid event streams are scoped by environment AND account (ADR-0018): event identity
  `(environment, account_fp, event_id)`, per-account sync cursor, payment epoch, event-sync lock and
  sync health, refund identity `(environment, account_fp, refund_id)`. After a legitimate account
  switch the new account's stream starts at event 1 without being mistaken for duplicates.

### WooCommerce compatibility
- Minimum WooCommerce 8.7 (ADR-0022): 8.5/8.6 do not persist `refunded_payment` of HPOS refunds
  updated after creation (fixed in WooCommerce 8.7.0, PR #44214).

### Operations
- Configuration status (Ready / Disabled / Incomplete / Attention required) on the settings
  screen, diagnostics page and Site Health; classified connection test results.
- Diagnostics: monitored payments, overdue and failed background jobs, WP-Cron status, refund
  counts, the configured account's event stream (cursor, epoch, backlog, sync health), retained
  events of previous accounts, consecutive event-sync failures (with backoff).
- The recurring reconciliation stops when nothing is left to maintain (no monitored payment, open
  refund, event backlog or failed sync) even if payments once existed; verified webhooks still
  trigger event sync (ADR-0021). An event deferred for a retry counts as backlog until it is
  processed, ignored or abandoned, so its retry is never left without a scheduled job.
- Settled refunds are watched for returns as long as the refunded debit is (its unauthorized
  return window), at least 14 days; Plaid documents no refund return window (ADR-0021).
- Order panel with dates, return windows, refunds and earlier attempts; "Pay by Bank" column in
  the orders list; WP-CLI `refunds` and `simulate-refund` (Sandbox) commands.
- Accessible payment page (WCAG 2.2 AA checked with axe-core), "What happens next" steps and a
  Sandbox badge; clear message when the customer closes Plaid Link.

### Release engineering
- Reproducible release ZIP (same commit → same SHA-256), package verifier, pristine-install
  smoke test, and a release workflow that publishes only the exact artifact that passed the full
  quality gate, including the real Plaid Sandbox gate.
- CI matrix: PHP 8.1–8.4, WordPress 6.6–7.1, WooCommerce 8.7–11.1, MySQL 8.0/8.4 and
  MariaDB 10.11/11.4, HPOS on and off.
- Engineering documentation, ADRs and this changelog are version-controlled; a CI gate checks
  that they stay tracked and that relative links resolve. The Plaid documentation copy is a
  git-ignored local cache (`scripts/fetch-plaid-docs.sh`).
- Source audit gate: no `.env`, traces, IDE state, caches or credentials in the source archive.
- `vendor-prefixed/` is regenerated with a pinned Composer version (2.10.3) and must match the lock.
- Pristine package smoke on a fresh HPOS store and a fresh legacy-storage store (no forced storage
  switch); browser E2E failures report the phase, server log, `debug.log` and a core backtrace.
- The real Plaid Sandbox gate requires the Sandbox Link customization and is mandatory for tags.
  The job reports whether it was EXECUTED; a release also fails when the gate did not really run.
  Its web server uses the browser suite's PHP settings (JIT off) and prints diagnostics on failure.
- The latest CI rows run WordPress 7.1.2 and WooCommerce 11.1.2.
- Fixed a false pass in the browser E2E runner: `playwright-cli run-code` ends a run when a native
  `confirm()` opens (WooCommerce's refund confirmation) and exits successfully, so every step after
  the admin refund was silently skipped. The runner now requires the suite's own final result and
  the suite answers the confirmation in-page. The steps that now run revealed a WCAG colour-contrast
  failure of the order panel's notices inside WooCommerce's `#order_data` box (fixed).
- Root cause of the CI browser failure (`net::ERR_EMPTY_RESPONSE`): GitHub's setup-php enables
  OPcache's tracing JIT by default, and PHP 8.2's built-in server workers then crash in the Zend VM
  (`SIGSEGV in execute_ex`) while serving WordPress core requests. Reproduced locally with the same
  settings; the suite passes with OPcache on and the JIT at PHP's default (off). The browser suite's
  web server now always runs with the JIT off (`tests/fixtures/php-server/`), locally and in CI.
- Fixed a flaky integration assertion that built a "wrong" order key equal to the real one when
  the key already ended with the replacement character (about 1 run in 62).

### Security audit (1.0.0)
- Reviewed: duplicate transfers and refunds, returned-transfer retries, wrong-account event
  ingestion, cursor poisoning, cross-account refund lookup, webhook replay, JWT verification and
  body hash, CSRF, IDOR, SSRF, open redirects, secret and PII leakage, admin actions. No known
  Critical or High issue remains. See `docs/SECURITY.md`.

## 0.1.0

Initial release of PayBridge for Plaid — WooCommerce Pay by Bank.

- Pay by Bank (ACH debit) through Plaid Transfer UI: Transfer Intent + Link token created
  server-side, Plaid Link on the WooCommerce order-pay page, Classic checkout and Checkout
  Blocks.
- Server-authoritative completion: `/transfer/intent/get` and `/transfer/get` are compared
  with an immutable payment snapshot; the browser never marks an order paid.
- At most one active Transfer Intent per order (durable reservation + MySQL advisory lock);
  safe handling of timeouts and 5xx during intent creation.
- Verified Plaid webhooks (ES256 JWT, key rotation by `kid`, `iat` window, body hash) that
  only schedule `/transfer/event/sync`; idempotent, leased event processing; explicit
  payment state machine; ACH returns flagged to the merchant.
- Background reconciliation with Action Scheduler; transfers of other stores or
  integrations on the same Plaid account are recognised and ignored.
- HPOS and legacy order storage; verified order persistence.
- Optional Funding Account ID (Plaid Ledger accounts leave it empty).
- Admin: write-only secret, connection test, diagnostics page, Site Health checks, order
  panel with **Sync with Plaid**, persistent alerts; WP-CLI `wp paybridge-plaid`.
- Diagnostics show the last verified and the last rejected webhook.
- Plaid account history from before the store's first payment is skipped without API calls.
- Plaid rate limiting (HTTP 429) is always retried: it never negatively caches a webhook key
  or permanently classifies a transfer event.
- Conservative, opt-in uninstall cleanup of PayBridge-owned data only.
