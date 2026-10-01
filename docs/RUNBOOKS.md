# Operational Runbooks

## 0. Tools

| Tool | Where | Use |
|---|---|---|
| PayBridge order panel | Order edit screen | Attempt, Plaid IDs and statuses, dates and return windows, refunds, earlier attempts, alerts; **Sync with Plaid**, **Cancel bank payment**, manual-review actions |
| Orders list | WooCommerce → Orders, "Pay by Bank" column | Returned / review / in-flight payments at a glance |
| PayBridge diagnostics | WooCommerce → PayBridge diagnostics | Status checklist, operational counts, health timestamps, support report |
| Site Health | Tools → Site Health | Configuration and background processing |
| Logs | WooCommerce → Status → Logs, source `paybridge-for-plaid` | Redacted structured logs (enable **Debug logging** for request detail) |
| Action Scheduler | WooCommerce → Status → Scheduled Actions, group `paybridge-for-plaid` | `paybridge_plaid_transfer_event_sync`, `paybridge_plaid_reconcile`, `paybridge_plaid_reconcile_continue` |
| WP-CLI | `wp paybridge-plaid …` | See below |

```bash
wp paybridge-plaid status                 # diagnostics report
wp paybridge-plaid test-connection        # classified readiness check
wp paybridge-plaid sync-events            # pull /transfer/event/sync now and process events
wp paybridge-plaid reconcile              # event sync + due payments + due refunds
wp paybridge-plaid sync-order 123         # re-read one order's payment and refunds from Plaid
wp paybridge-plaid refunds 123            # list PayBridge refunds and the refundable amount
wp paybridge-plaid fire-sandbox-webhook   # Sandbox only: ask Plaid to send a signed webhook
wp paybridge-plaid simulate-refund <refund-id> refund.settled   # Sandbox only
```

Tables: `{prefix}paybridge_plaid_events` (event history), `{prefix}paybridge_plaid_payment_locks`
(reservation + payment index), `{prefix}paybridge_plaid_refunds` (refunds).

## 1. Order stuck on-hold

1. Open the order panel: environment, transfer ID/status, last sync.
2. **Sync with Plaid** (or `wp paybridge-plaid sync-order <id>`).
3. Check the logs by order/transfer/request ID and the Action Scheduler list.
4. If diagnostics show overdue jobs: configure a real cron (`wp-cron.php` or
   `wp action-scheduler run` every minute), especially with `DISABLE_WP_CRON`.
5. Never invent a transfer ID or status.

## 2. Webhook verification failures

1. The endpoint must be public HTTPS and registered in Plaid Dashboard → Team Settings →
   Webhooks as a **Transfer event** webhook for the right environment.
2. Check that `Plaid-Verification` survives proxies/CDNs and that nothing rewrites the body.
3. Diagnostics show the last verified and last rejected webhook (reason, HTTP status).
4. Do not disable verification — reconciliation keeps payments moving meanwhile.

## 3. Event sync backlog

1. Diagnostics: event stream (environment / account), cursor, last successful sync, last error
   (category), consecutive failures, backlog — all for the configured Plaid account.
2. `permanent` errors: fix credentials (Test connection). `transient`: retries back off to 15 min.
3. `wp paybridge-plaid sync-events`. The cursor never passes unstored events; replays are safe.
4. `abandoned` events (20 attempts) need investigation (`error_code`); `ignored` events with
   `before_first_payment`, `foreign_transfer`, `foreign_site`, `foreign_refund`,
   `environment_mismatch`, `unknown_attempt` belong to other integrations/stores.

## 4. Suspected duplicate payment or refund

1. Do not create another transfer or refund.
2. Compare attempt/intent/transfer/refund IDs and Plaid request IDs (panel, notes, logs,
   `wp paybridge-plaid refunds`) with the Plaid Dashboard.
3. **Sync with Plaid**. Record the incident and add a regression test.

## 5. ACH return

1. The order is Failed with the return code, reason and a red notice; alert and email sent.
2. Original paid date, transaction ID and history are kept (ADR-0012).
3. PayBridge never debits the order again (ADR-0019): Plaid allows reprocessing a returned transfer
   only for R01/R09, at most twice, within 180 days and only as a marked `/transfer/create` retry,
   which Transfer UI cannot send; R10 and other unauthorized returns may never be resubmitted. The
   order panel shows the reason. Contact the customer and collect the payment another way (another
   payment method, bank transfer, a new order only after a NEW customer authorization and within
   your ACH obligations). Restock manually if appropriate.

## 6. Return after a refund (critical alert)

1. The customer's bank reversed the debit after you refunded: the customer may have received the
   money twice. Pending refunds were cancelled automatically where Plaid still allowed it (see notes).
2. Contact the customer; decide collection with Plaid support/your policy.
3. Dismiss the alert after recording the decision in an order note.

## 7. Refund problems

| Alert | Meaning | Action |
|---|---|---|
| Refund failed | Plaid could not send the refund; no money reached the customer | Delete the failed WooCommerce refund record (refund row trash icon), then refund again |
| Refund returned | The customer's bank returned the refund; money is back in your Plaid balance | Contact the customer for correct bank details; delete the record and refund again |
| Refund unconfirmed | Plaid did not confirm the create (timeout) | Do nothing; PayBridge adopts it or marks it void within ~30 min; then refund again if void |
| Refund cancelled | Cancelled before submission (e.g. after a return) | Review the order; delete the WooCommerce refund record if no refund is owed |
| Refund created outside WooCommerce | A Dashboard refund was found | Record it in WooCommerce as a manual refund (without "Refund via …") |
| "Plaid rejected the refund (CODE)" | e.g. insufficient Ledger balance, limit reached | Fix the cause (fund the Ledger) and retry; nothing moved |

## 8. Payment in manual review

1. The panel shows the reason. Nothing is fulfilled automatically.
2. Decide: fulfil manually, refund (when the transfer settled), or cancel the transfer while
   Plaid allows it. Record the decision with the panel buttons (audited).
3. Review without a transfer: **Let the customer pay again** (allowed only when no Link token
   can still be used and Plaid confirms no transfer exists).

## 9. Credentials or account changes

1. Rotating the secret of the same Client ID: allowed any time.
2. Changing the Client ID or environment is refused while Production payments or refunds are
   monitored (ADR-0015). Wait until diagnostics show 0 monitored payments (about three months
   after the last settlement), or keep the current account.
   After a switch the new account has its own event stream (starts at event 1), cursor, epoch and
   sync health; diagnostics show the new stream and count the previous account's events as
   "previous accounts (audit only)". Nothing of the previous account is read with the new
   credentials (ADR-0018).
3. Production credentials rejected: confirm Production Transfer approval, re-enter the secret,
   **Test connection** (classified result), leave Funding Account ID empty with Plaid Ledger.

## 10. Disabling Pay by Bank

Uncheck "Offer Pay by Bank at checkout". Existing payments, returns and refunds keep being
monitored; do not remove credentials while diagnostics show monitored payments. When no payment,
refund or event of the account needs monitoring anymore, the recurring reconciliation stops by
itself ("Background maintenance active: No"); verified webhooks still trigger event sync
(ADR-0021).
