# Payment Lifecycle

## 1. Checkout initiation

1. Shopper selects **Pay by Bank** (Classic Checkout or the Checkout block).
2. Classic Checkout validates the billing first and last name before creating the order
   (`validate_fields()`); the account holder's legal name is never invented (ADR-0013).
3. WooCommerce creates/loads the order and calls `process_payment()`.
4. PayBridge refuses new payments unless the gateway is enabled and completely configured
   (credentials, valid environment, USD, HTTPS in Production and a Link customization in both
   environments, ADR-0020), and never for an order whose earlier transfer was returned (ADR-0019).
5. Under the order's database mutex: an existing usable intent is reused; otherwise an
   immutable snapshot is created (amount, currency, environment, attempt ID) and a durable
   reservation acquired (ADR-0003); the attempt records the Plaid account fingerprint (ADR-0015)
   and the account's payment epoch is recorded before the first remote call (ADR-0011, ADR-0018).
6. `/transfer/intent/create` is called with `mode=PAYMENT`, the snapshot amount, `ach_class=web`,
   the merchant's bank statement description, the legal name, email and correlation metadata.
7. The intent ID is persisted (index first, then order meta) before the shopper leaves.
8. The shopper is redirected to the PayBridge payment page.

## 2. PayBridge payment page

WooCommerce's order-pay receipt page, protected by order key + ownership or the guest's
session grant (a numeric ID is never enough). It shows only server-derived values: order
number, amount, what happens next, a Plaid security note and — in Sandbox only — a discrete
"Sandbox" badge. The button requests a Link token bound to the stored intent and the merchant's
Link customization; a token is only returned after its expiry is durably recorded.

## 3. Transfer UI

The browser opens Plaid Transfer UI. `onSuccess` means the flow finished, not that money
moved. The browser sends only a completion signal. On exit or error the button becomes a
retry and keyboard focus returns to it.

## 4. Server verification

The server re-validates access, loads the stored intent, calls `/transfer/intent/get`, checks
it against the snapshot (amount, currency, mode, attempt), binds the `transfer_id` through a
compare-and-set in the payment index, reads `/transfer/get` (amount, type, dates) and projects
`transfer_created` → On hold. Repeated or concurrent completion calls are harmless.

## 5. Asynchronous processing

```text
Plaid ── TRANSFER_EVENTS_UPDATE ──► verified webhook (ES256 JWT, body hash, iat)
        ──► Action Scheduler (unique job) ──► /transfer/event/sync (cursor of the configured account)
        ──► durable event store (environment + account) ──► payment events → payment state machine → WooCommerce
                                                        └► refund events (refund_id) → refund state machine
Reconciliation (every 15 min while there is work, independent of the enabled switch, ADR-0021):
        event sync + /transfer/get of due payments + /transfer/refund/get of due refunds
```

## 6. ACH debit lifecycle

```text
pending → posted → settled → funds_available
pending → failed                       (no money moved)
posted/settled/funds_available → returned   (money reversed)
```

The order is marked paid at `funds_available` (default) or `settled` (setting). PayBridge
keeps monitoring a paid payment until Plaid's unauthorized return window closes
(`MonitoringPolicy`, ADR-0014): every 6 h inside the standard window, daily until the
unauthorized window (+2 days), with a 7/95-day fallback when Plaid reports no dates.

## 7. Returned payments (ADR-0012)

A return:

- sets the payment state `returned` with return code, reason and timestamp;
- sets the WooCommerce status **Failed** (unless Failed/Refunded) with a private note;
- raises a persistent admin alert, emails the merchant, marks the order in the orders list
  ("Bank payment returned (R01)") and the order panel;
- when refunds were already issued for the same payment: cancels still-pending refunds at
  Plaid and raises a critical `returned_after_refund` alert, note and email with the amount
  that already left the merchant (ADR-0016).

Accounting semantics after a return:

| Item | Behavior |
|---|---|
| `date_paid` | Kept (the payment really completed before it was returned). A repayment sets it to the repayment's time; the first paid time stays in the attempt history. |
| Transaction ID | Kept (returned transfer). A repayment replaces it with the new transfer ID; the returned one stays in the history. |
| WooCommerce reports | Failed is excluded from revenue by default (WooCommerce Analytics "Excluded statuses"). |
| Stock | Not restocked automatically (goods may have shipped); merchant decision. |
| Emails | Merchant email from PayBridge; no automatic customer email. |
| Fulfilment history | Notes, statuses and meta are never deleted. |
| Download permissions | Blocked by WooCommerce while the order is Failed. |
| Customer retry | **None by bank.** Plaid allows reprocessing a returned transfer only for R01/R09, at most twice, within 180 days and marked "Retry 1/2" on `/transfer/create`; Transfer UI cannot mark a debit as a retry, and R10/R07/R11… may never be resubmitted. PayBridge therefore never debits the order again (§8, ADR-0019): Pay by Bank is hidden on its order-pay page, the customer is told why, and the merchant collects the payment another way. |

## 8. Repayment after a failed attempt (ADR-0017) — never after a return (ADR-0019)

After a failure before money moved (intent declined or failed, unknown intent outcome, transfer
`failed` or `cancelled`, released manual review) a new payment is a new attempt: the previous
attempt (intent, transfer, state, amounts, dates, failure data) is archived in the order's attempt
history, the payment index is reset under the reservation, and a new snapshot, intent, Link token
and transfer are created. Late events of the archived attempt never change the new one; its
refunds stay tracked.

After a **return**, `ReturnRetryPolicy` refuses every new debit for the order at the single entry
point (`PaymentAttemptService`): checkout, the pay link, Link tokens, the REST route and "Let the
customer pay again" all stop before any reservation or Plaid call. The returned attempt stays the
order's current attempt, with its original transfer, for auditing.

## 9. Refunds (ADR-0016)

`Refund via Pay by Bank` in the WooCommerce order screen → `process_refund()`:

1. eligibility (settled/funds_available — Plaid would allow earlier refunds, but PayBridge
   deliberately waits for a settled debit to avoid refunding money that can still fail; cancel an
   unsettled transfer instead — same environment and account, ≤ 180 days,
   < 10 refunds, no unconfirmed refund, amount ≤ remaining) and a one-minute double-submit guard;
2. durable reservation with a deterministic idempotency key, then `/transfer/refund/create`;
3. success → Plaid refund ID on the WooCommerce refund and a note (with a double-loss warning
   while the debit can still be returned); definitive error → WooCommerce shows Plaid's reason,
   nothing moved; unknown outcome → "do not refund again", resolved automatically from Plaid;
4. refund events and reconciliation drive `pending → posted → settled` (or failed/cancelled/
   returned); failures and returns raise alerts and emails.

Full refunds set WooCommerce's Refunded status (WooCommerce behavior). Partial refunds can be
repeated until the remaining amount is zero.

## 10. Interrupted flows

| Situation | Behavior |
|---|---|
| Browser closes before Link | Intent stays pending; the unpaid-order cancellation is held while a Link token can be used; reconciliation checks it until the authorization window closes. |
| Link exit | No payment; the customer can retry the same intent. |
| Timeout/5xx creating an intent | `intent_uncertain`; the unknown intent can never get a Link token; the next attempt creates a new intent. |
| DB write fails after the intent was created | The intent ID is durable in the reservation and adopted by the next request. |
| Timeout/5xx creating a refund | Same-key retry, Plaid refund list lookup, then `uncertain` → adopted or void. |
| Webhook lost | Reconciliation's event sync recovers it. |
| Merchant disables the gateway | New payments stop; existing ones are still monitored until their return windows close and their refunds are final; then background jobs stop (a webhook still triggers event sync). |
| Merchant switches Plaid account | Allowed once nothing of the previous account is monitored (ADR-0015); the new account's event stream starts at event 1 with its own cursor and epoch, the previous account's are kept for auditing (ADR-0018). |
| Merchant cancels a pending transfer | "Cancel bank payment" while Plaid reports it cancellable; state from Plaid. |

## 11. WooCommerce status projection

See `docs/STATE_MACHINE.md` §4. Order completion (processing vs completed) is always
WooCommerce's decision via `payment_complete()`.
